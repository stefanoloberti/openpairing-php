# THP product acceptance plan

This is the fail-closed test plan for a future ChessPairings candidate that
embeds OpenPairingPHP. It converts the integration inventory into observable
acceptance checks. It does not authorize a production deployment and it does
not claim that the inspected ChessPairings checkout already implements this
boundary.

Every execution is tied to one validated
`openpairing.thp-candidate-identity/1` manifest and one source-controlled
ChessPairings database-migration set. A source, mapping, migration, runtime or
OpenPairing change starts a new identity and a new evidence stream.

## Test layers

| ID | Layer | Required observation | Fail-closed result |
|---|---|---|---|
| MAP-001 | Snapshot | The same product state produces byte-identical canonical JSON regardless of database row order or locale | Reject unknown, missing or ambiguous state |
| MAP-002 | Semantics | Played/unplayed results, forfeits, PAB, withdrawals, scheduled byes, acceleration and prohibited groups survive the mapping exactly | Reject any lossy or guessed mapping |
| MAP-003 | Initial colour | The first-round colour is persisted before request creation and is identical on retry | Reject an absent or indeducible value; never redraw |
| ID-000 | Source freeze | `tools/verify_thp_source_freeze.php` attests the exact clean product commit, all tracked source and declared migration files, and a passing redacted secret scan | Reject dirty/untracked source, missing migration coverage, symlinks or a secret finding before candidate creation |
| ID-001 | Candidate | Request, response, PTC, RTG and shadow all carry the expected candidate-identity SHA-256 | Reject identity drift before any write |
| ID-002 | Input | `tools/verify_thp_pairing_request.php` passes the request envelope and exact canonical snapshot | Reject hash, revision, path or derived-metadata mismatch |
| ID-003 | Output identity | `tools/verify_thp_pairing_result.php` binds the result to that request, the closed candidate artifacts and the declared engine version/hash | Reject stale job/revision/input, candidate drift or functional-hash mismatch |
| OUT-001 | Accounting | Every eligible participant appears exactly once as paired or PAB; no ineligible participant appears | Reject partial, duplicate or extra output |
| OUT-002 | Reciprocal state | Opponents, colours, board numbers, PAB and all hard constraints pass an independent product-side validator | Reject the complete result, not individual boards |
| OUT-003 | Post-processing | Requested byes and withdrawals are either part of canonical engine I/O or one separately hash-attested deterministic transform | Reject unowned append/remove behavior |
| CAS-001 | Stale revision | `tools/verify_thp_precommit_guard.php` rejects a re-observed product revision that differs from the original request | Discard the whole result and request a fresh snapshot |
| CAS-002 | Stale hash | The same guard rejects a changed fresh canonical-input hash even when the revision did not advance | Treat as an integrity error; perform no write |
| TX-001 | Atomic success | The exact product harness records one complete commit in `openpairing.thp-transaction-probe-batch/1`; `tools/verify_thp_transaction_probes.php` verifies all six write stages | No partial observable state |
| TX-002 | Injected failure | The same batch contains one failure after every write stage, each with zero commits and the exact baseline revision/state hash restored | Original tournament remains byte/logically identical |
| MODE-001 | FIDE allow-list | `tools/verify_thp_mode_probes.php` checks one identity-bound successful route per candidate-declared system plus an unknown-system rejection, with counted engine/fallback/write calls | No legacy/manual fallback |
| MODE-002 | Unsupported input | The same batch proves team state, record `299` and another undeclared feature stop before the individual engine call with stable diagnostics | Explicit unsupported-input diagnostic and zero writes |
| ASYNC-001 | Durable job | The exact product harness records worker restart/reload in `openpairing.thp-recovery-probe-batch/1`; `tools/verify_thp_recovery_probes.php` binds the four scenarios to the same candidate/source/migration identity | No duplicate publication or anonymous retry |
| ASYNC-002 | Late completion | The same batch proves a superseded job leaves the newer revision and logical-state hash unchanged with zero commits/publications | Mark stale and preserve the newer state |
| REC-001 | Recovery | The same batch proves crash-before-commit, crash-after-commit and duplicate delivery have exact, idempotent state/commit/publication outcomes | No half-round and no double application |
| SHD-001 | Isolation | Shadow timeout, crash or non-exact result cannot change the primary response, publication or retry path | Observation is recorded separately or not observed |
| SHD-002 | Privacy | Exported NDJSON contains only the allowed pseudonymous fields and hashes | Verifier returns infrastructure error and evidence is quarantined |
| SEC-001 | Distribution secrets | The exact source, installer and runtime contain no embedded provider credential, token, private key or environment-specific secret | Stop packaging, rotate the exposed credential through the owner and rebuild a new candidate identity |
| SEC-002 | Runtime configuration | Required secrets are injected through documented deployment configuration with least privilege and are absent from diagnostics/evidence | FIDE mode and submission packaging remain disabled |
| PERF-001 | Budget | `tools/verify_thp_performance_probes.php` checks the complete candidate-declared system/size matrix, repeated deterministic output, zero writes and every-sample latency/memory budgets | Product gate remains open on any overrun, missing probe or non-deterministic output |

## Mandatory fixture families

Golden product-to-canonical fixtures must cover both initial colours, odd and
even fields, round one and later rounds, forfeits with an opponent, every
supported scheduled-bye result, a PAB, withdrawal/reactivation, late entry,
Baku virtual points, prohibited groups, and maximum supported player/round
limits. Each fixture contains only synthetic players and stores:

- product fixture schema/version and database migration identity;
- expected canonical snapshot SHA-256 and expected request envelope;
- expected eligible-player denominator and round metadata;
- expected engine result or exact fail-closed diagnostic;
- expected product-side validation and transaction outcome.

No fixture may normalize away a field merely because a current external
engine ignores it. The mapping contract is judged against the declared FIDE
product state, not against a legacy transport's permissiveness.

## Execution order

1. Run the read-only source-freeze verifier on the exact ChessPairings Git
   root and every migration path, then build the candidate identity only from
   the emitted commit and manifest hashes.
2. Close MAP and pre-call ID checks with no engine call and no database mutation.
3. Close the post-call identity, OUT and portable pre-commit guard checks against synthetic in-memory or
   disposable-database data; verifier `GO` must still leave product state
   untouched.
4. Exercise CAS and every TX failure injection in an isolated database, then
   validate the identity-bound probe batch without exporting personal data.
5. Exercise crash/restart, late-completion and duplicate-delivery scenarios in
   the isolated product environment, then validate the recovery probe batch;
   close FIDE-mode behavior separately.
6. Run exact-candidate staging fixtures, measure the complete pre-write product
   route, and validate its identity-bound performance probe batch.
7. Start the non-mutating observer in `SHADOW-VALIDATION.md` only after owner,
   privacy and operational review.

The current offline corpus may continue in parallel. Historical equality does
not waive any product test above, and product acceptance does not replace the
TEC PTC/RTG cross-tests.
