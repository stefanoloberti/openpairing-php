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
| ID-001 | Candidate | Request, response, PTC, RTG and shadow all carry the expected candidate-identity SHA-256 | Reject identity drift before any write |
| ID-002 | Input | `tools/verify_thp_pairing_request.php` passes the request envelope and exact canonical snapshot | Reject hash, revision, path or derived-metadata mismatch |
| ID-003 | Output identity | `tools/verify_thp_pairing_result.php` binds the result to that request, the closed candidate artifacts and the declared engine version/hash | Reject stale job/revision/input, candidate drift or functional-hash mismatch |
| OUT-001 | Accounting | Every eligible participant appears exactly once as paired or PAB; no ineligible participant appears | Reject partial, duplicate or extra output |
| OUT-002 | Reciprocal state | Opponents, colours, board numbers, PAB and all hard constraints pass an independent product-side validator | Reject the complete result, not individual boards |
| OUT-003 | Post-processing | Requested byes and withdrawals are either part of canonical engine I/O or one separately hash-attested deterministic transform | Reject unowned append/remove behavior |
| CAS-001 | Stale revision | Changing any relevant tournament row while the engine runs makes the pre-commit revision comparison fail | Discard the whole result and request a fresh snapshot |
| CAS-002 | Stale hash | A state change that does not advance the revision is still detected by recomputing the canonical input hash | Treat as an integrity error; perform no write |
| TX-001 | Atomic success | Games, histories, points, round state, bye resets and request/result attestations commit together | No partial observable state |
| TX-002 | Injected failure | A failure injected after each individual write rolls back every write in the transaction | Original tournament remains byte/logically identical |
| MODE-001 | FIDE allow-list | FIDE mode selects only the declared engine/system and rejects unknown identifiers | No legacy/manual fallback |
| MODE-002 | Unsupported input | Team state, record `299` and any undeclared feature stop before the individual engine call | Explicit unsupported-input diagnostic |
| ASYNC-001 | Durable job | Restarting a worker preserves the opaque job, input hash, revision and candidate identity | No duplicate publication or anonymous retry |
| ASYNC-002 | Late completion | A superseded job cannot commit after a newer tournament revision or accepted job | Mark stale and preserve the newer state |
| REC-001 | Recovery | Crash before commit, during rollback and after commit has one documented, idempotent recovery outcome | No half-round and no double application |
| SHD-001 | Isolation | Shadow timeout, crash or non-exact result cannot change the primary response, publication or retry path | Observation is recorded separately or not observed |
| SHD-002 | Privacy | Exported NDJSON contains only the allowed pseudonymous fields and hashes | Verifier returns infrastructure error and evidence is quarantined |
| SEC-001 | Distribution secrets | The exact source, installer and runtime contain no embedded provider credential, token, private key or environment-specific secret | Stop packaging, rotate the exposed credential through the owner and rebuild a new candidate identity |
| SEC-002 | Runtime configuration | Required secrets are injected through documented deployment configuration with least privilege and are absent from diagnostics/evidence | FIDE mode and submission packaging remain disabled |
| PERF-001 | Budget | Exact delivered build stays within declared per-turn latency and memory budgets across supported sizes | Product gate remains open on an overrun |

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

1. Freeze a source-controlled ChessPairings candidate and migrations.
2. Close MAP and pre-call ID checks with no engine call and no database mutation.
3. Close the post-call identity and OUT checks against synthetic in-memory or
   disposable-database data; verifier `GO` must still leave product state
   untouched.
4. Exercise CAS and every TX failure injection in an isolated database.
5. Close FIDE-mode, asynchronous restart and recovery behavior.
6. Run exact-candidate staging fixtures and the product budget.
7. Start the non-mutating observer in `SHADOW-VALIDATION.md` only after owner,
   privacy and operational review.

The current offline corpus may continue in parallel. Historical equality does
not waive any product test above, and product acceptance does not replace the
TEC PTC/RTG cross-tests.
