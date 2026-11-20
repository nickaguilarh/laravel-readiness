<?php

declare(strict_types=1);

namespace Readiness;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-instance drain marker.
 *
 * A drained instance keeps serving traffic but reports itself as not ready, so
 * a load balancer has time to stop routing to it before maintenance mode, a
 * migration or a restart begins.
 *
 * The marker is a single file local to the instance, never shared storage,
 * because draining one instance must not drain the others. Its modification
 * time is the moment it expires. That keeps the hot path, isDraining(), to one
 * stat call with no container, config or file read, and means a forgotten
 * marker heals itself instead of keeping the instance out of rotation forever.
 */
final class Drain
{
    public const DEFAULT_TTL = 900;

    public const DEFAULT_MAX_TTL = 3600;

    private const MAX_REASON_LENGTH = 200;

    private const MAX_FILE_BYTES = 4096;

    private static ?string $path = null;

    private static int $maxTtl = self::DEFAULT_MAX_TTL;

    /**
     * Point the marker at a file. The service provider does this at boot.
     */
    public static function configure(?string $path, int $maxTtl = self::DEFAULT_MAX_TTL): void
    {
        self::$path = $path;
        self::$maxTtl = max(1, $maxTtl);
    }

    public static function path(): ?string
    {
        return self::$path;
    }

    public static function maxTtl(): int
    {
        return self::$maxTtl;
    }

    /**
     * The hot path: one stat call. Safe to call straight from a route closure.
     */
    public static function isDraining(): bool
    {
        $path = self::$path;

        if ($path === null) {
            return false;
        }

        // Long lived workers (Octane, queue workers) would otherwise read a
        // stat result cached from an earlier request.
        clearstatcache(true, $path);

        $expiresAt = @filemtime($path);

        return $expiresAt !== false && $expiresAt > time();
    }

    /**
     * Start draining for $ttl seconds. Returns the state that was written.
     *
     * @return array{started_at: int, expires_at: int, reason: string|null}
     */
    public static function start(int $ttl = self::DEFAULT_TTL, ?string $reason = null, ?int $now = null): array
    {
        $path = self::requirePath();

        if ($ttl < 1 || $ttl > self::$maxTtl) {
            throw new InvalidArgumentException(sprintf('The drain ttl must be between 1 and %d seconds.', self::$maxTtl));
        }

        // A symlink here would let whoever controls it redirect our write.
        if (is_link($path)) {
            throw new RuntimeException('Refusing to use a symlink as the drain marker.');
        }

        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The drain marker directory is missing or not writable.');
        }

        $startedAt = $now ?? time();
        $expiresAt = $startedAt + $ttl;
        $state = [
            'started_at' => $startedAt,
            'expires_at' => $expiresAt,
            'reason' => self::sanitizeReason($reason),
        ];

        $contents = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // Write beside the target and rename, so a reader never sees a
        // half written marker.
        $temporary = tempnam($directory, '.drain-');

        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary drain marker.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false
                // tempnam creates the file as 0600, a web server running as
                // another user has to be able to read the marker.
                || ! chmod($temporary, 0644)
                || ! touch($temporary, $expiresAt)
                || ! rename($temporary, $path)) {
                throw new RuntimeException('Could not write the drain marker.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        clearstatcache(true, $path);

        return $state;
    }

    /**
     * Stop draining. True when a marker existed, expired or not.
     */
    public static function stop(): bool
    {
        $path = self::requirePath();

        clearstatcache(true, $path);

        if (! file_exists($path) && ! is_link($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Details of the active drain, or null when the instance is not draining.
     *
     * @return array{started_at: int|null, expires_at: int, remaining: int, age: int|null, reason: string|null}|null
     */
    public static function status(?int $now = null): ?array
    {
        if (! self::isDraining()) {
            return null;
        }

        $now ??= time();
        $expiresAt = (int) @filemtime((string) self::$path);

        $state = self::readState();
        $startedAt = isset($state['started_at']) ? (int) $state['started_at'] : null;
        $reason = isset($state['reason']) && is_string($state['reason']) ? $state['reason'] : null;

        return [
            'started_at' => $startedAt,
            'expires_at' => $expiresAt,
            'remaining' => max(0, $expiresAt - $now),
            'age' => $startedAt === null ? null : max(0, $now - $startedAt),
            'reason' => $reason,
        ];
    }

    /**
     * A bare 503 for routes that answer for themselves. No reason, host or
     * timing is exposed, the caller only learns the instance is draining.
     */
    public static function unavailable(): Response
    {
        $remaining = self::status()['remaining'] ?? 0;

        $response = new Response(
            json_encode(['status' => 'draining'], JSON_THROW_ON_ERROR),
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Content-Type' => 'application/json'],
        );

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        if ($remaining > 0) {
            $response->headers->set('Retry-After', (string) $remaining);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readState(): array
    {
        $contents = @file_get_contents((string) self::$path, false, null, 0, self::MAX_FILE_BYTES);

        if ($contents === false || $contents === '') {
            return [];
        }

        $state = json_decode($contents, true);

        return is_array($state) ? $state : [];
    }

    private static function sanitizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $reason) ?? '';
        $reason = trim($reason);

        if ($reason === '') {
            return null;
        }

        return function_exists('mb_substr')
            ? mb_substr($reason, 0, self::MAX_REASON_LENGTH)
            : substr($reason, 0, self::MAX_REASON_LENGTH);
    }

    private static function requirePath(): string
    {
        if (self::$path === null) {
            throw new RuntimeException('The drain marker path is not configured.');
        }

        return self::$path;
    }
}
