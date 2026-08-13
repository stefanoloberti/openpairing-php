# Development toolchain inventory

## Current status (2026-08-13)

Local Windows uses the mandatory 30-bit-limb backend because ext-gmp is not
installed. The `ChessPairings-Ubuntu-24.04` laboratory WSL has ext-gmp and
Xdebug installed for isolated profiling/backend experiments; staging services
and their PHP configuration were not changed. Runtime reports attest the
selected arithmetic backend and whether GMP is available.

Xdebug was subsequently disabled for the WSL CLI SAPI with `phpdismod -s cli
xdebug` because a function profile generated 7.7 GB before one 80-player round
completed and because a loaded Xdebug module disables PHP JIT. Apache/staging
module configuration was not changed.

Inspected/acquired on 2026-08-11. Nothing was installed globally.

| Tool | Version | Location/use | Integrity |
|---|---|---|---|
| Git | 2.53.0.windows.1 | Existing local executable | Environment inventory |
| Python | 3.13.0, NetworkX 3.6 | Existing local Gacrux oracle only | Environment inventory |
| PHP | 8.4.24 NTS x64 | `tools/runtime/php`, ignored | Official `windows.php.net` SHA-256 `86470a30cbbaeafb259e727dfa5cd336f2f3f0a462cd6f8e3eac00fdbded13cb` |
| Composer | 2.10.2 | `tools/runtime/composer.phar`, ignored | Official `getcomposer.org` SHA-256 `5ee7125f8a30a34d246cefdc0bc85b8a783b28f2aec968994118512350d28027` |
| PHPUnit | 11.5.56 | Composer dev dependency | Locked in `composer.lock` |
| PHPStan | 2.2.8 | Composer dev dependency | Locked in `composer.lock` |
| GMP | PHP 8.3 package `8.3.6-0ubuntu0.24.04.10` | Laboratory WSL only; native arithmetic experiment | Ubuntu package repository; runtime identity records selection |
| Xdebug | 3.2.0 package | Laboratory WSL only; function-profile negative control | Produced 7.7 GB before one 80-player round completed and is not used for gates |

The `ChessPairings-Ubuntu-24.04` WSL environment on `doppia-3090` supplies PHP
8.3.6, Python 3.12.3 and Git 2.43.0. Git was confirmed/installed through the
Ubuntu package manager on 2026-08-12. Content-addressed validation releases and
the frozen TRF corpus live under `/tmp`. Staging was atomically redeployed on
2026-08-13 to `production-current-20260813-openpairing-ed4ddd4`; its pre-switch
gate passed 224 tests/33,047 assertions, PHPStan, syntax and CLI smoke checks,
then the live local endpoint and the runtime ZIP download returned HTTP 200.
The served runtime archive has SHA-256
`5de95fa121906a1f921e1814f9a4133916c12787fc81e4c11b0adbdbf6b3fd90`.
The source archive SHA-256 is
`c1a9b9c9271b18bd869839a568dc897300f75cdf282bdcf9c0419c556a2e331d`.
Both archives are reproducible across two clean builds of commit `ed4ddd4`;
the runtime was additionally verified on PHP 8.3.6 with byte-identical GMP and
limb outputs for pairing and standings examples.

Release candidate 2 was subsequently deployed atomically to
`production-current-20260813-openpairing-4243326`. Its pre-switch gate passed
228 tests/33,056 assertions, PHPStan, syntax and CLI checks; HTTP and the served
runtime SHA-256 were verified after the switch. RC2 source SHA-256 is
`f46c527d17896bceea75e397eab9a1589e36dd6dd7a51c243126b426ecf20ff1` and
runtime SHA-256 is
`d5ba158144328b72c9805cdf0f5df09318f74c77f8c5ddcd23815b73aa424a12`.
Two clean builds are byte-identical. PHP 8.3.6 artifact smokes additionally
cover `XXA` import/canonicalization and a 500-player initial round.

Release candidate 3, tagged at `8e98553`, changes package version and
documentation only relative to the rc2 algorithms. It was built twice with
byte-identical source/runtime archives and deployed atomically to
`production-current-20260813-openpairing-8e98553`. The pre-switch PHP 8.3.6
gate passed 228 tests/33,056 assertions, PHPStan, syntax and CLI checks; GMP
and limb pairing/standings outputs, XXA canonicalization, the 500-player smoke,
HTTP and the served download hash were verified. Source SHA-256 is
`b653e016357f64c59435f36584c0960dd147ec0138ed71004155875d72905b9b`;
runtime SHA-256 is
`3e3559239a517372d5a70933d4108f2a28d13b9e1f4bc8c6d378fb85c2a62ceb`.
The separate local stress gate passed 5 tests/31,003 assertions (one GMP-only
case skipped on Windows); the upstream oracle group passed 11 tests/55
assertions.

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
