# Corpus gate

## Current status

This is the single entry point for the historical pairing-verification
campaign. A partition is closed only when its committed evidence records the
complete denominator, exact/reference comparison, source tree, replay harness
and corpus identity. Parser/round-trip coverage is not pairing verification.

As of 2026-10-01, all 122,008 decodable tournaments in the historical
denominator have closed partition evidence. Of 2,220,579 attempted historical
rounds, 2,220,576 match the recorded reference exactly; the other three are
misc deviations whose PHP pairs exactly match Gacrux's computed pairs. There
are zero colour variants, no-solutions or resource limits. This is a
qualified historical replay result, not a claim that every recorded historical
pairing was optimal, nor a complete accelerated-corpus or FIDE gate. The p150
and misc aggregate/receipt pairs below independently reconcile every final
report and classification, including 26 primary-validated cross-host imports.
The separately identified acceleration pairing gate closed on 2026-10-03;
its evidence and receipt are recorded below. Larger-field capacity and
product/FIDE gates remain open.

At the 2026-08-26 checkpoint, 119,998 historical tournaments and
2,038,198 exact rounds were already closed. An early p50 run was stopped
after 14/14 candidate deviations proved inferior. That diagnostic was
then invalidated as a product verdict: the replay aged float history by stored
participations rather than tournament-round identity. The isolated RC6 fix
makes all 20 affected file/round tuples exact and reconfirms the small-field
gate at 500/500. The fresh p50 replay closed at 30,000/30,000 tournaments and
768,324/768,324 exact rounds under the corrected semantic identity and
per-shard fail-closed Gacrux watcher. p80 epoch 4 then closed at 20,000/20,000
tournaments and 660,000/660,000 exact rounds. Its 256 report/classification
pairs are complete and all classifications are `PASS_NO_ORACLE_CASES`. At that
time p150 was active and historical misc remained queued behind it. A disjoint
ICQ worker was computing only whole p150 shards `s112`-`s127`; its output
was quarantine-only at that checkpoint and could not become historical
evidence until the 3090 validated the original shard corpus and identity,
ran Gacrux and promoted the
classification last. The bootstrap checkpoint is
`evidence/cross-worker-icq-p150-bootstrap-20260826.json`; it records no
accepted p150 evidence and therefore does not change the denominator.
A second disjoint p150 worker on `python-69` was assigned `s080`-`s111`
with ten workers, in a new service and quarantine that do not alter its
preserved 64/64 p80 cross-host result. Its bootstrap checkpoint is
`evidence/cross-worker-python69-p150-bootstrap-20260826.json`; like the ICQ
checkpoint, it authorized computation but contributed nothing until primary
validation, Gacrux classification and classification-last promotion succeeded.
Acceleration and the newly accepted >256-player scope are tracked separately
so they cannot silently change the historical denominator.

The immutable p150 aggregate is
`evidence/p150-full-86ec9d9-20261001.json` (SHA-256
`492fa4f73c0ccc6b3f2d3137e41a97c19a04ece65a1ad8af05d70484391858c8`);
its independent receipt is
`evidence/p150-full-86ec9d9-20261001-receipt.json` (SHA-256
`252318ae8322ec58856306b0cd01ec031ae1b84b192ff712a1300b066344285e`).
The immutable misc aggregate is
`evidence/misc-full-86ec9d9-20261001.json` (SHA-256
`7ac92446a96baf99458a8de027df49fb66d9d26071ae1a8d446a7aa32a26106f`);
its independent receipt is
`evidence/misc-full-86ec9d9-20261001-receipt.json` (SHA-256
`19f51d567a7751575533663ede20e37c10de3dca2588f7999afe3c6ccc0c9a92`).
The interpretation of the three misc cases, including independent Gacrux
checks of the original historical pairings, is
`evidence/misc-checker-exact-review-20261001.json`. Here `checker-exact`
means *exactly the pairs computed by Gacrux*, not a distinct equally optimal
pairing. Under this checker the three original historical pairings are worse
at QC9; their historical context is not inferred from that result.

The subsequent acceleration epoch 4 stopped fail-closed with 20/20 shards in
quarantine because its historical-float replay omitted virtual acceleration
points. Those reports are not promoted. A distinct epoch-5 replay under
campaign-tool commit `5b0e7648663e92d7d08790fa6b899f01dd76a718` and
harness SHA-256 `1c8013f591a81e5d57393340466a4c2cec1ddac6ac87b027b6dcdbc9e58d829b`
corrects that reconstruction without changing the Dutch engine SHA-256.
The corrected epoch-5 replay closed with 64/64 nonempty report/classification
pairs, 1,000/1,000 tournaments and 11,000/11,000 reference-exact rounds.
All 64 classifications are `PASS_NO_ORACLE_CASES`: there were no non-exact
rounds requiring Gacrux adjudication. Zero colour variants, deviations,
no-solutions, resource limits, STOP or nonempty error files were found. The
independent verifier reconciled every basename, report identity, corpus
manifest, counter and classification against the immutable aggregate before
promotion. Its SHA-256 is
`3c64f91513e2d7b17ab6681de72ec4cbe99644bd3f31ba9925902a4efd135901`
at `evidence/accel-full-epoch5-virtual-float-20261003.json`; the independent
receipt is `evidence/accel-full-epoch5-virtual-float-20261003-receipt.json`
(SHA-256 `0922eb05c51d2a6d6631dcf6f6ff96b7eac247c33fecfbe04ff80f339cace993`).
This is a separate replay identity, not an epoch-4 continuation or a second
oracle replay of every round. The epoch-4 STOP and quarantine remain preserved.

The immutable p80 aggregate is
`evidence/p80-full-86ec9d9-20260826.json` (SHA-256
`e191f3fe27c4afb75144da2a145467034cc3b10f03fdd4f3732b3d8ea7617533`).
The independent promotion receipt that reconciles all 256 report and
classification basenames, identities, sentinels and health conditions is
`evidence/p80-full-86ec9d9-20260826-receipt.json` (SHA-256
`f050f2e6f4b7e0a12ec010436fe6048c52e789c1c8a6e0a14730bccb3389a2ec`).

The first three completed p50 shards provide the official measured 20-worker
throughput sample of 237 tournaments: 83.677 seconds per tournament under
contention. It projects p50 at 34.87 hours and p80 at 97.75 hours. A later
timing-only snapshot of 20 completed shards, 1,580 tournaments and 40,304
rounds projects p50 at 35.47 hours, confirming that the estimate is stable.
The p80 value remains a projection scaled from its attested ten-tournament
baseline, not a completed p80 measurement. Evidence is
`evidence/campaign-eta-p50-p80-20w-20260813.json` and
`evidence/p50-partial-20-progress-20260813.json`.

A 16 August read-only throughput diagnostic compared the active first wave
with all 17 preserved reports from the superseded p80 run. Those reports took
70,549-71,873 seconds per shard (19.60-19.96 hours); s000 alone took
71,521 seconds for 79 tournaments and 2,589 rounds. The active s000 worker was
at 99.9% CPU with GMP and OPcache JIT both active, so zero promoted reports at
13 hours was consistent with the measured baseline. The first primary
completion window remains approximately 19:40-20:05 Europe/Rome on 16 August,
before inline classification time. The current verifier emits its JSON only
at shard completion and has no identity-bound intermediate round counter;
that observability gap must be fixed before a future epoch, not retrofitted
into this one. Evidence is
`evidence/p80-throughput-diagnostic-20260816.json`.
The required privacy-minimised, atomic and explicitly non-evidentiary channel
is specified in `CORPUS-SHARD-OBSERVABILITY.md`. Its frozen JSON envelope and
independent fail-closed reader are implemented and verified by
`evidence/corpus-shard-heartbeat-verifier-checkpoint-20260816.json`. The
writer and runner integration remain future-epoch work; the then-active p80
runner and identity were not changed.

The 17:00 Europe/Rome autonomy-window handoff is recorded in
`evidence/autonomy-closeout-1700-20260816.json`. It leaves both p80 services
running and enabled, records zero promoted epoch-4 shards at close, and keeps
submission readiness at `NO_GO`.

The first accepted epoch-4 wave closed on 17 August with 30/256 shard pairs:
20 computed on the primary and 10 whole, disjoint shards (`s192`-`s201`)
computed on `python-69` and accepted only after primary integrity validation
and Gacrux classification. The combined snapshot covers 2,360 tournaments and
77,664/77,664 exact rounds, with zero colour variants, deviations,
no-solutions or resource limits. All 30 classifications are
`PASS_NO_ORACLE_CASES`; no STOP exists. The cross-host path initially failed
closed during importer/transport commissioning and promoted no partial final
pair. Its immutable importer and first accepted wave are recorded in
`evidence/cross-worker-first-accepted-import-20260817.json`.

The definitive negative control then transported `s192` through the same
GZip and intake path, altered the laboratory copy by one byte and observed the
immutable importer reject it with exit 13 for a raw SHA-256 mismatch. No final
artifact or STOP was created and the active 30/30 report/classification
denominator remained unchanged. This closes the transport-integrity control;
the preserved laboratory path and hashes are in
`evidence/cross-worker-final-channel-negative-20260817.json`.

The historical 20-shard snapshot found 14 candidate pairing deviations and
zero no-solutions. Gacrux classified all 14 as
`inferior-to-checker-optimum`; their first differences were HE2 (9), HO1 (3)
and HO5 (2). This remains a valid disproof of the old replay semantics, not of
RC6. A narrow remainder-pair-count experiment changed 0/14 and was rejected;
the round-indexed float fix changed all 14. Its three round-filter reports are
`evidence/p50-float-age-round-29.json` through
`evidence/p50-float-age-round-31.json`. The p300/p600 capacity pilots remain
independent. Historical evidence is
`evidence/p50-partial-20-gacrux-summary-20260813.json` and
`evidence/p50-partial-20-gacrux-diagnostic-20260813.json`.

Official replay identity for the closed p80/p150/misc epoch-4 partitions:

- Git campaign-tool commit: `86ec9d9c003d68468906bddf12c69fbcaaf750ac`
- source tree SHA-256: `a42e9e60024ce2526e247544f36d39a657163e56e814e9b76c7d34593a48e884`
- harness SHA-256: `a617aa2677cdf3d0ad204caa73b18d6dceceb61b1d2d65ba95f9669a8ab06c4c`
- float semantics: `fide-dutch-float/3-round-indexed`
- arithmetic: GMP; OPcache CLI and JIT enabled; 20 workers
- persistent root: `/srv/openpairing-validation`
- shard acceptance: report plus Gacrux classification promoted atomically;
  persistent stop on the first confirmed inferior or incomplete verdict

At 00:01-00:04 Europe/Rome on 2026-08-16, the existing Windows WSL
keep-alive task was found to have a failing action. Transient WSL sessions
therefore produced four short boot cycles. No p80 report or classification had
been promoted, so the active evidence denominator remains unchanged. The
non-checkpointable p600 pilot had no promoted report and restarted from zero;
the already closed p300 capacity result remains historical evidence, while its
boot-triggered rerun is a fresh, unaccepted computation. The existing
keep-alive task now holds the distribution open, and both capacity oneshots
are disabled for future boots without stopping their current processes. The
incident and exact runner hashes are recorded in
`evidence/future-runner-backend-hardening-20260816.json`.

The collector had already copied the completed p300 result atomically to the
Windows evidence staging area before the reboot. Its exact archived payload is
now preserved as `evidence/capacity-pilot-p300-20260815.json` (SHA-256
`fa293a6c6f2e94d790c4be0ad726afbd2843156361a425d3af3b87b508f56147`)
with the matching GNU time record in
`evidence/capacity-pilot-p300-20260815.time.txt` (SHA-256
`967e166290c6e2501d3e02056ad67b0e66362c4248bc06ddd52aeb449ccc6be2`).
That capacity-only identity verified one 300-player tournament and 61/61
rounds exactly, with zero colour variants, deviations, no-solutions or
resource limits, in 84,894.272 seconds and 1,459,748 KiB maximum RSS. It is not
relabelled as evidence for the active RC8 corpus identity.

The queued p150, misc and accelerated runners were regenerated without
touching the then-active p80 runner. They now lock the effective
`OPENPAIRING_BIGINT_BACKEND=gmp`, reject completed reports whose production,
backend, source or harness identity differs, and record both the campaign
runner and shard-classifier hashes in each accepted classification.

The first p80 launch on 2026-08-15 is quarantined and is not official evidence.
Its supervisor selected release `c48fd05` instead of the identity above, and
the old replay harness silently supplied `152 W` when a TRF omitted the initial
colour. It stopped after 17 promoted reports with 217 colour variants and zero
pairing deviations. The fixed harness infers the value only from a played
round-one board, attests `record-152` or `inferred-round-one` per file, and
fails closed if inference is impossible. A backward scan found zero inference
mismatches against 34,791 explicit records and zero indeducible files; all 217
observed variants replay exact. The public codec now applies the same TRF26
conditional rule: it infers `152` from a scheduled round-one board and fails
closed when that is impossible.

The intermediate `9406571` epoch closed the launch-control gaps but was stopped
before accepting any shard when RC7 added the public PTC/RTG and completed the
TRF26 codec. This was a deliberate candidate-identity realignment, not a
verdict or campaign failure. Epoch 3 started at 21:32:52 Europe/Rome under
`c759bd9`, preserving the unchanged Dutch engine hash, three-part lock,
per-shard classification and 20-worker policy. Evidence is
`evidence/p80-initial-colour-provenance-20260815.json` and
`evidence/p80-rc7-realignment-20260815.json`.

Epoch 3 was subsequently superseded with zero accepted reports and zero
accepted classifications when RC8 added end-to-end TRF26 records `240` and
`260`. The launch preflight rejected an initial RC8 packaging attempt whose
Unix executable modes were absent, before any service mutation. The corrected
immutable runtime is `/srv/openpairing-validation/releases/86ec9d9-r2`.
Epoch 4 started at 22:22:42 Europe/Rome after a deliberate wrong-identity test
failed with exit code 4; its 20 workers use the identity above. The independent
p600 pilot retained the same three processes across the rollover. Evidence is
`evidence/rc8-trf26-and-p80-realignment-20260815.json`.

## Historical denominator: 122,008

| Partition | Files | Decoded | Exact tournaments | Exact / attempted rounds | Status | Attested evidence |
|---|---:|---:|---:|---:|---|---|
| p7 | 10,000 | 10,000 | 10,000 | 49,961 / 49,961 | closed | `evidence/pairing-corpus-p7-full-4a93b7a.json` |
| p9 | 10,000 | 9,999 | 9,999 | 69,922 / 69,922 | closed; one corrupt input | `evidence/pairing-corpus-p9-full-14539a6.json` |
| p15 | 30,000 | 29,999 | 29,999 | 269,991 / 269,991 | closed; one corrupt input | `evidence/pairing-corpus-p15-sharded-14539a6.json` |
| p30 | 20,000 | 20,000 | 20,000 | 220,000 / 220,000 | closed | `evidence/pairing-corpus-p30-full-c48fd05.json` |
| p50 | 30,000 | 30,000 | 30,000 | 768,324 / 768,324 | closed | `evidence/p50-full-dcf92df-20260815.json` |
| p80 | 20,000 | 20,000 | 20,000 | 660,000 / 660,000 | closed under epoch 4 / `86ec9d9` | `evidence/p80-full-86ec9d9-20260826.json` |
| p150 | 2,000 | 2,000 | 2,000 | 182,000 / 182,000 | closed under epoch 4 / `86ec9d9` | `evidence/p150-full-86ec9d9-20261001.json` and receipt |
| historical misc | 10 | 10 | 8 | 378 / 381; 3 checker-exact deviations | closed, qualified under epoch 4 / `86ec9d9` | `evidence/misc-full-86ec9d9-20261001.json`, receipt and review |
| **Total** | **122,010 read** | **122,008 decodable and verified** | **122,006 reference-exact** | **2,220,576 / 2,220,579; 3 classified deviations** | **historical rows closed; acceleration closed separately** | — |

The two read/decoded differences are corrupt corpus records, not engine
rejections. The 122,008 denominator is the historical pairing gate used before
the parser gained acceleration and larger-field support.

### Frozen identities for closed partitions

| Partition | Source tree SHA-256 | Harness SHA-256 | Corpus/manifest SHA-256 |
|---|---|---|---|
| p7 | `20a801e5a98b3874ba086649a67af5279e89d3253852d8d9b5e1e668f88041a6` | `0990f14de24c4ad624dafafbc6b7ed248c0ca7f8f7ad58f1cc957be6ae0210f7` | `faf0dc8e0f1618b703f9a270bfcb9ee8ad9fd26fd3a330a753cbd9dccb3445ba` |
| p9 | `bbfbbbb3e6c62ed10252a6d0c44e41e1036bd2033da78a648eb91aa41d43bba6` | `0990f14de24c4ad624dafafbc6b7ed248c0ca7f8f7ad58f1cc957be6ae0210f7` | `ecd419456f4563ac9505d4f5ee3a4aed83603072f3fc71a5d0d940bd0ab64c64` |
| p15 | `bbfbbbb3e6c62ed10252a6d0c44e41e1036bd2033da78a648eb91aa41d43bba6` | `0990f14de24c4ad624dafafbc6b7ed248c0ca7f8f7ad58f1cc957be6ae0210f7` | `ea160e208976b2c6ce75ba8aee92c5975112d2e8bb494a1160962487f80fc559` |
| p30 | `fb06fa59b66d4eba8e6e41b97ba42600e29c99075c057153b970f9eca2fd9ba3` | `f241e9d50267e2f597cb4f8549f75b4d5e30d376e66c2b3081d6d786dce47093` | `162d78844b3f04cdcff7dedc03401f95dbd629a4f8960e5f5f33257021b1fe3b` |
| p80 | `a42e9e60024ce2526e247544f36d39a657163e56e814e9b76c7d34593a48e884` | `a617aa2677cdf3d0ad204caa73b18d6dceceb61b1d2d65ba95f9669a8ab06c4c` | `3a76297c8706e960f4396e1c5ff15f99def248c676ca9f0cfecf23f52f1ab884` |
| p150 | `a42e9e60024ce2526e247544f36d39a657163e56e814e9b76c7d34593a48e884` | `a617aa2677cdf3d0ad204caa73b18d6dceceb61b1d2d65ba95f9669a8ab06c4c` | `21dedd80ab9182f3cfd6302023de51b3940c5d0b5d427217036c34eb98201c0b` |
| historical misc | `a42e9e60024ce2526e247544f36d39a657163e56e814e9b76c7d34593a48e884` | `a617aa2677cdf3d0ad204caa73b18d6dceceb61b1d2d65ba95f9669a8ab06c4c` | `21de0160243ed55e3e4468e383b0964703a55ebf8cf6c4bd1f0b7d03719b1151` |

These are distinct evidence epochs. Aggregates must never merge incompatible
identities even when all outputs are exact.

## Added parser and capacity scope

| Partition | Files | Parser/round-trip | Pairing gate |
|---|---:|---|---|
| accelerated `250` corpus | 1,000 | supported | corrected epoch 5 closed: 11,000/11,000 reference-exact; aggregate and receipt above; source corpus has no real `XXA` records |
| large misc | 1 | supported | queued |
| 300 players | 200 | supported | full gate open; one current-identity pilot running, no terminal verdict yet |
| 600 players | 200 | supported | full gate open; one current-identity pilot running, no terminal verdict yet |
| 1,200 players | 88 | supported | open; not promised before pilot verdict |
| 1,800 players | 41 | supported | open; not promised before pilot verdict |
| 2,400 players | 17 | supported | open; not promised before pilot verdict |
| **Added total** | **1,547** | **1,547 accepted** | **open** |

Together with the historical denominator, current parsing accepts 123,555 of
123,557 corpus files; only the two corrupt inputs remain excluded. This is not
yet a claim that all 123,555 have passed end-to-end pairing replay.

The August 300/600 pilots are preserved under their separate capacity identity;
their verifier JSON and `/usr/bin/time -v` telemetry remain under
`/srv/openpairing-validation/pilots/reports`. On 2026-10-03, two isolated,
low-priority, one-worker pilots were started against the immutable acceleration
epoch-5 release, one tournament each at 300 and 600 players. Their start
identity, input and runner hashes, resource limits and initial process evidence
are recorded in `evidence/capacity-epoch5-pilots-launch-20261003.json`.
Neither pilot has a terminal classification yet; neither closes any of the 547
capacity cases. The bulk 300-2,400 queue will not start automatically until
measured feasibility is reviewed.

## Acceptance and publication rules

For each partition the final row must record:

1. the complete, reconciled denominator and corruption/exclusion reasons;
2. exact, colour-variant, deviation, no-solution and resource-limit counts;
3. Gacrux classification for every non-exact round, never an unclassified
   “equivalent variant” bucket;
4. source, harness and corpus hashes plus arithmetic backend;
5. a committed JSON under `docs/evidence/`, produced by an official clean-tree
   replay; and
6. a matching branch/tag pushed to the private GitHub backup.

The public badge, landing-page claim and Milvang report must be generated from
this document and its linked JSON evidence. Until every historical row is
closed, the only valid corpus claim is the explicitly stated closed subtotal.

## Operations and safety evidence

- Windows legacy scheduled-task inventory:
  `evidence/task-scheduler-backup-20260813/` (pre/post inventories, 21 XML
  rollback definitions, eleven authorized tasks disabled across two phases,
  none deleted; nine triggerless PTC/PublicGate definitions retained pending a
  separate owner decision).
- WSL keepalive and fail-closed supervisor operation: `TOOLCHAIN.md`.
- Runtime verification method and historical epochs:
  `HISTORICAL-PAIRING-VERIFICATION.md` and `ORACLE-FIRST-METHOD.md`.
