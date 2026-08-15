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

Release candidate 4, tag `59b8b9d`, is a documentation-only successor to rc3.
Its two builds are byte-identical; source SHA-256 is
`da250766e47b78eda4ac8f0e00c9d3bbf549179997ad631a3940571fafbfca6a` and
runtime SHA-256 is
`a716dbc883f3d756b61ce186a5a61b5385f05aa058b912a75ddd6b59c7098a1f`.
It was deployed atomically to
`production-current-20260813-openpairing-59b8b9d`: 228 tests/33,056
assertions, PHPStan, lint, PHP 8.3 GMP/limb examples, XXA normalization, HTTP
and the served runtime hash all passed before/after the switch. Its separate
stress gate passed 5 tests/31,003 assertions (one Windows GMP skip), and the
upstream oracle group passed 11 tests/55 assertions.

Release candidate 5, tag `59e8924`, packages the same validated algorithms and
the latest product documentation. Two clean builds are byte-identical. Source
SHA-256 is
`d1c214a717f3a42c96be4327230563146ec732320ee82cd8b4be5deef371bdf8`;
runtime SHA-256 is
`1fdb1a800c333806c85717b049c42067013d940a73bcf1099bd3c470173d19b6`.
Its local rapid, stress and oracle gates passed; stress executed 5 tests and
31,003 assertions in 403.036 seconds with the expected Windows-only GMP skip,
and the oracle group executed 11 tests/55 assertions. RC5 was then deployed
atomically to
`production-current-20260813-openpairing-59e8924`: its PHP 8.3.6 pre-switch
gate passed 228 tests/33,056 assertions, PHPStan, lint and CLI smoke. HTTP, the
download index, all historical runtime links and the served RC5 runtime hash
were verified after the switch. Versioned deployment evidence is
`docs/evidence/release-rc5-staging-59e8924.json`. No further RC number is
allocated merely for documentation or corpus progress.

Release candidate 6, tag `b6a2c1b`, corrects float-history ageing across
unplayed rounds. It was deployed atomically to
`production-current-20260813-openpairing-rc6-b6a2c1b`; its PHP 8.3.6 gate
passed 232 tests/33,070 assertions, PHPStan, lint and CLI identity checks. The
staging root and both downloadable RC6 archives were verified over HTTP after
the switch. Runtime SHA-256 is
`cd4dc4c3115de8fc25e8614204175b01d495dafa161ba0cf7b7c34d16d56128e`;
source SHA-256 is
`1ad200fdc1bfe5cc02ab345fd9c3202d3665be391a0c0c8fde48133a3fb9a75c`.
Versioned evidence is `docs/evidence/release-rc6-staging-b6a2c1b.json`.

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

`tools/push_checkpoint.ps1` is the gate-close backup step. It requires a clean
named branch, pushes the current branch and all tags to both the private GitHub
`origin` and the OneDrive bare `offsite` remote, then fails unless each remote
branch resolves to the exact local commit. Run it after every official gate
commit; the remote verification is part of the checkpoint, not a best-effort
copy.

Long corpus campaigns are installed under persistent WSL storage at
`/srv/openpairing-validation`. The current queue uses 20 concurrent PHP/GMP
workers and small atomic shards: p30 20,000/256, p50 30,000/384, p80
20,000/256, p150 2,000/128, historical misc 10/1 and acceleration
1,000/64. Separate post-corpus capacity gates cover the one newly accepted
large misc tournament and 546 cases at 300-2,400 players. A single boot-enabled
`openpairing-full-corpus.service` supervisor invokes campaign then Gacrux
classification for each partition in strict sequence; shell fail-fast prevents
the next partition from starting after any non-zero gate. On reboot it starts
again at p30, whose atomic completed shards make every stage idempotent. The
original multi-unit `OnSuccess` graph was rejected after its `Requires=` edges
re-entered completed oneshot services and its parallel fan-out was not truly
fail-closed. Every aggregate remains fail-closed on mixed identities, missing
files or omitted oracle-required cases.

`tools/install_corpus_supervisor.sh` installs the fail-closed Linux supervisor
after verifying all eight campaign/classification stages. Because WSL may stop
systemd when its final Windows client exits, the host also runs the
non-privileged scheduled task installed by `tools/install_wsl_keepalive.ps1`;
it holds one `wsl.exe ... /bin/sleep infinity` process for the laboratory
distribution. The negative control observed p50 stop with WSL after 13-15
seconds repeatedly; with the task running, the same service and boot remained
active beyond the previous shutdown window. Two earlier full Windows reboots
were independently attributed by Event 1074 to local FIDE-laboratory
automation (`wmiprvse.exe` at 05:12 and an explicit sandbox credential-repair
`shutdown.exe` at 05:33), not to OOM or the OpenPairing engine. The latter
sandbox task is disabled.

The keepalive task lives in the Windows Task Scheduler root as
`OpenPairing-WSL-KeepAlive`, runs as the interactive `ai` account and starts at
logon. It can be paused without deleting its definition with
`Stop-ScheduledTask -TaskName OpenPairing-WSL-KeepAlive` followed by
`Disable-ScheduledTask -TaskName OpenPairing-WSL-KeepAlive`; re-enable it with
`Enable-ScheduledTask` and `Start-ScheduledTask`. Disabling it may allow WSL to
terminate once its last Windows client exits, so the corpus service must be
checked afterwards. `tools/audit_windows_legacy_tasks.ps1` produces a read-only
inventory of relevant legacy tasks; it never changes their state.

On 2026-08-13 the authorized scheduler cleanup exported all 21 armed relevant
task definitions before changing anything, then disabled the five remaining
0.45.5 pipeline tasks. A separately authorized second phase disabled six
historical one-off or competing mass-run tasks. Every changed task was
re-queried as `Disabled`, all protected tasks were re-queried as not disabled,
and no task was deleted. The inventories, XML rollback definitions, operation
results and PTC/PublicGate decision table are under
`docs/evidence/task-scheduler-backup-20260813/`. Ten armed tasks remain: the
intentionally retained `OpenPairing-WSL-KeepAlive` and nine triggerless
PTC/PublicGate definitions awaiting a separate owner decision. Any new
scheduled task on this machine that is related to ChessPairings, FIDE
laboratory work or OpenPairing must be recorded in this section and in a
refreshed inventory before it is relied upon.

The worker count was raised from 12 to 20 only after the shutdown causes were
separated from engine load. At the scale checkpoint the WSL exposed 28 logical
CPUs, load 12.03, 45 GiB available RAM, zero swap use and roughly 60 MiB RSS
per verifier. Twenty workers leave eight logical CPUs for the host, staging and
classification; every campaign script uses the same value so reboot recovery
does not silently change concurrency.

Before a large-field campaign accepts a completed shard,
`tools/corpus/classify_shard_fail_fast.sh` checks fatal engine counters and
classifies every retained deviation with Gacrux. A confirmed inferior result,
an incomplete classification, a no-solution/resource-limit result or an
oracle infrastructure error creates an atomically written persistent stop
file. Workers must check that file before starting the next shard. Reports with
zero evaluated files/rounds are rejected; reports with evaluated rounds but
zero oracle-required cases receive an explicit zero-case PASS record rather
than invoking the classifier. This per-shard gate is the permanent early-stop
watcher introduced after the p50 partial run exposed a semantic defect long
before the partition aggregate could be produced. The systemd supervisor uses
`Restart=no`: a gate failure remains stopped for investigation, while the
enabled unit still resumes the idempotent queue after a machine boot.
Accepted classification records point to the promoted report path and include
the SHA-256 of the shard-gate script, so the decision mechanism is part of the
evidence rather than an unrecorded property of the host.

Product-release identity and campaign-tool identity are intentionally distinct.
For an active replay, monitoring and acceptance compare the runtime lock with
the campaign identity declared in `CORPUS-GATE.md`, not with the product tag in
`RELEASE.md`. The RC6 product commit `b6a2c1b` is an ancestor of the campaign
tooling. The p50 checkout was `dcf92df`; the short-lived p80 launch-control
epoch was `9406571`, and the RC7-aligned active queue is
`c759bd9a97a5639bc8cec38029b6ad4e1a40b58c`, with source-tree SHA-256
`353317beb2fb3c0135e149a104db6333ab1681f7e82a1e12e605d907810338e9`.
All retain float semantics `fide-dutch-float/3-round-indexed` and the same
Dutch engine SHA-256. Comparing a campaign checkout directly with the RC6 tag
caused and then disproved a monitoring false positive on 2026-08-13.

The same launch exposed a quoting defect in the first generated p50 runner:
literal quotes reached `xargs -P` as `"20"`, causing six fail/restart cycles
between 13:36:32 and 13:39:20. Commit `eabc29e` corrected the generator before
the immutable `dcf92df` campaign checkout was installed. The installed p50
runner contains `xargs -r -P "$workers"` and passed `bash -n`.

The first p80 transition later selected the old `c48fd05` runner. It was
stopped and quarantined after 17 reports. The corrected architecture uses four
distinct campaign directories (`p80`, `p150`, `misc` and `accel`); the active
set is suffixed `epoch3-c759bd9`, and the supervisor contains only those
identity-scoped stages. The intermediate `epoch2-9406571` set accepted zero
shards before the RC7 realignment and remains preserved as non-evidence.
Installation and every shard now verify a three-part campaign lock:
Git campaign-tool commit, runtime source-tree SHA-256 and replay-harness
SHA-256. Negative controls independently changed each field; every mismatch
exited before a runner was created. The integration gate also asserts the
required generated xargs form and absence of the escaped-quote regression.
