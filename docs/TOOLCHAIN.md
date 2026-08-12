# Development toolchain inventory

Inspected/acquired on 2026-08-11. Nothing was installed globally.

| Tool | Version | Location/use | Integrity |
|---|---|---|---|
| Git | 2.53.0.windows.1 | Existing local executable | Environment inventory |
| Python | 3.13.0, NetworkX 3.6 | Existing local Gacrux oracle only | Environment inventory |
| PHP | 8.4.24 NTS x64 | `tools/runtime/php`, ignored | Official `windows.php.net` SHA-256 `86470a30cbbaeafb259e727dfa5cd336f2f3f0a462cd6f8e3eac00fdbded13cb` |
| Composer | 2.10.2 | `tools/runtime/composer.phar`, ignored | Official `getcomposer.org` SHA-256 `5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027` |
| PHPUnit | 11.5.56 | Composer dev dependency | Locked in `composer.lock` |
| PHPStan | 2.2.8 | Composer dev dependency | Locked in `composer.lock` |

An optional read-only inventory found PHP 8.3.6 and Python 3.12.3 in the
`ChessPairings-Ubuntu-24.04` WSL environment on `doppia-3090`. Only `php -v`
was executed there; no project file, staging service or corpus was accessed.
Project verification uses the ignored local PHP 8.4.24 runtime.
