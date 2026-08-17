# Corpus gate

## Current status

This is the single entry point for the historical pairing-verification
campaign. A partition is closed only when its committed evidence records the
complete denominator, exact/reference comparison, source tree, replay harness
and corpus identity. Parser/round-trip coverage is not pairing verification.

As of 2026-08-15, 99,998 of the historical 122,008 decodable tournaments are
closed: 1,378,198/1,378,198 attempted rounds are exact, with zero colour variants,
pairing deviations, no-solutions or resource limits. An early p50 run was
stopped after 14/14 candidate deviations proved inferior. That diagnostic was
then invalidated as a product verdict: the replay aged float history by stored
participations rather than tournament-round identity. The isolated RC6 fix
makes all 20 affected file/round tuples exact and reconfirms the small-field
gate at 500/500. The fresh p50 replay closed at 30,000/30,000 tournaments and
768,324/768,324 exact rounds under the corrected semantic identity and
per-shard fail-closed Gacrux watcher. p80 epoch 4 is active; p150 and historical
misc remain queued behind it.
Acceleration and the newly accepted >256-player scope are tracked separately
so they cannot silently change the historical denominator.

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
writer and runner integration remain future-epoch work; the active p80 runner
and identity were not changed.

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

Official replay identity for the active queue:

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
touching the active p80 runner. They now lock the effective
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
| p80 | 20,000 | 2,360 partial | 2,360 partial | 77,664 / 77,664 partial | active epoch 4 under `86ec9d9`; 30/256 shards | aggregate pending |
| p150 | 2,000 | pending | pending | pending | queued | aggregate pending |
| historical misc | 10 | pending | pending | pending | queued | aggregate pending |
| **Total** | **122,010 read** | **122,008 expected decodable** | **99,998 closed** | **1,378,198 / 1,378,198 closed** | **open** | — |

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

These are distinct evidence epochs. Aggregates must never merge incompatible
identities even when all outputs are exact.

## Added parser and capacity scope

| Partition | Files | Parser/round-trip | Pairing gate |
|---|---:|---|---|
| accelerated 250/XXA | 1,000 | supported | queued after historical partitions |
| large misc | 1 | supported | queued |
| 300 players | 200 | supported | full gate suspended; one end-to-end pilot running |
| 600 players | 200 | supported | full gate suspended; one end-to-end pilot running |
| 1,200 players | 88 | supported | open; not promised before pilot verdict |
| 1,800 players | 41 | supported | open; not promised before pilot verdict |
| 2,400 players | 17 | supported | open; not promised before pilot verdict |
| **Added total** | **1,547** | **1,547 accepted** | **open** |

Together with the historical denominator, current parsing accepts 123,555 of
123,557 corpus files; only the two corrupt inputs remain excluded. This is not
yet a claim that all 123,555 have passed end-to-end pairing replay.

The 300/600 pilots run as persistent, low-priority systemd services and record
their complete verifier JSON plus `/usr/bin/time -v` telemetry under
`/srv/openpairing-validation/pilots/reports`. The bulk 300-2,400 queue will not
start automatically until measured feasibility is reviewed.

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
