# Security policy

## Supported versions

Security fixes go to the latest release. The package supports the Laravel
versions listed in `composer.json`. Several of those lines have open Laravel
advisories and no longer get fixes from Laravel. Running them is your decision
and your risk.

## Reporting a vulnerability

Please do not open a public issue. Report it privately through
[GitHub private vulnerability reporting](https://github.com/nickaguilarh/laravel-readiness/security/advisories/new).

Include the version, what you did and what you saw. A confirmed issue is fixed
in a patch release and credited in its advisory unless you prefer otherwise.

## What the package protects, and what it does not

- The readiness report is only served to an allowed address or a caller with
  the token. With neither configured, it refuses everyone.
- The token is compared in constant time.
- Exception messages stay out of the report unless `expose_errors` is on,
  because driver errors often carry hosts and usernames.
- The drain marker refuses to follow a symlink, is written atomically and
  expires on its own.
- There is no HTTP endpoint that starts or stops a drain. It is only done with
  the `drain` and `undrain` commands, which need shell access.
- Behind a proxy, `$request->ip()` is only the real client if you configured
  trusted proxies. Otherwise an address allow-list can be bypassed with a
  forged `X-Forwarded-For`.

## How the repository is checked

Every push runs `composer audit`, gitleaks and PHPStan at level 8. Pull
requests also run a dependency review. Workflows have read-only permissions
and every action is pinned to a commit.
