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

The `ChessPairings-Ubuntu-24.04` WSL environment on `doppia-3090` supplies PHP
8.3.6, Python 3.12.3 and Git 2.43.0. Git was confirmed/installed through the
Ubuntu package manager on 2026-08-12. Content-addressed validation releases and
the frozen TRF corpus live under `/tmp`; staging remains read-only until the
500/500 shadow gate passes.

`composer check` is the sub-minute edit gate: unit/contract/small differential
tests plus PHPStan, excluding `oracle` and `stress`. `composer checkpoint` is
the pre-replay gate and additionally executes the 1,000-graph static and
sequential differentials, the 300-vertex capacity test and development-oracle
tests. An official replay must use a committed tree and record its commit plus
runtime source hash.

`tools/commit.ps1 -Message "..."` commits an already staged, otherwise clean
tree only after `composer check` succeeds. `-Wip` marks an intentionally
incomplete branch checkpoint, but the wrapper still requires the rapid gate to
be green. Official replays never use WIP or dirty trees.
