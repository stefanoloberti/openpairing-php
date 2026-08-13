# Corpus gate

## Current status

This is the single entry point for the historical pairing-verification
campaign. A partition is closed only when its committed evidence records the
complete denominator, exact/reference comparison, source tree, replay harness
and corpus identity. Parser/round-trip coverage is not pairing verification.

As of 2026-08-13, 69,998 of the historical 122,008 decodable tournaments are
closed: 609,874/609,874 attempted rounds are exact, with zero colour variants,
pairing deviations, no-solutions or resource limits. p50 is running with 20
workers. p80, p150 and historical misc remain open. Acceleration and the newly
accepted >256-player scope are tracked separately so they cannot silently
change the historical denominator.

The first three completed p50 shards provide a measured 20-worker throughput
sample of 237 tournaments: 83.677 seconds per tournament under contention.
This projects p50 at 34.87 hours and p80 at 97.75 hours. The p80 value is a
projection scaled from its attested ten-tournament baseline, not a completed
p80 measurement. Evidence is
`evidence/campaign-eta-p50-p80-20w-20260813.json`.

Official replay identity for the active queue:

- Git commit: `c48fd0529fd7d21bec830bebb6579e4506934761`
- source tree SHA-256: `fb06fa59b66d4eba8e6e41b97ba42600e29c99075c057153b970f9eca2fd9ba3`
- harness SHA-256: `f241e9d50267e2f597cb4f8549f75b4d5e30d376e66c2b3081d6d786dce47093`
- arithmetic: GMP; OPcache CLI and JIT enabled; 20 workers
- persistent root: `/srv/openpairing-validation`

## Historical denominator: 122,008

| Partition | Files | Decoded | Exact tournaments | Exact / attempted rounds | Status | Attested evidence |
|---|---:|---:|---:|---:|---|---|
| p7 | 10,000 | 10,000 | 10,000 | 49,961 / 49,961 | closed | `evidence/pairing-corpus-p7-full-4a93b7a.json` |
| p9 | 10,000 | 9,999 | 9,999 | 69,922 / 69,922 | closed; one corrupt input | `evidence/pairing-corpus-p9-full-14539a6.json` |
| p15 | 30,000 | 29,999 | 29,999 | 269,991 / 269,991 | closed; one corrupt input | `evidence/pairing-corpus-p15-sharded-14539a6.json` |
| p30 | 20,000 | 20,000 | 20,000 | 220,000 / 220,000 | closed | `evidence/pairing-corpus-p30-full-c48fd05.json` |
| p50 | 30,000 | pending | pending | pending | running | aggregate pending |
| p80 | 20,000 | pending | pending | pending | queued | aggregate pending |
| p150 | 2,000 | pending | pending | pending | queued | aggregate pending |
| historical misc | 10 | pending | pending | pending | queued | aggregate pending |
| **Total** | **122,010 read** | **122,008 expected decodable** | **69,998 closed** | **609,874 / 609,874 closed** | **open** | — |

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
  rollback definitions, five authorized 0.45.5 tasks disabled, none deleted).
- WSL keepalive and fail-closed supervisor operation: `TOOLCHAIN.md`.
- Runtime verification method and historical epochs:
  `HISTORICAL-PAIRING-VERIFICATION.md` and `ORACLE-FIRST-METHOD.md`.
