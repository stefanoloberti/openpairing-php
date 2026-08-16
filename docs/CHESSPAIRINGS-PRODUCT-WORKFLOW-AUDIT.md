# ChessPairings product-workflow audit

This is a source-level audit of the isolated ChessPairings Web line. The
original complete inventory used rc.21; the individual initial-colour and
atomic-start findings were subsequently corrected in source-only candidate
`5.21.0-rc.31`, commit
`6554280292fc2668c78a30a4933aa40d5d4ee299`. No product database, staging or
production was changed by that correction. The candidate is not FIDE approved
or endorsed and this audit does not close the `complete-thp-workflow` gate.

The inspected source is useful: it narrows the remaining work from a generic
"complete product" requirement to concrete, testable product gaps. Findings
and exact inspected-file hashes are frozen in
`evidence/chesspairings-product-workflow-audit-20260816.json`.
The follow-up source identities, tests and still-open runtime boundary are
frozen in
`evidence/chesspairings-initial-colour-checkpoint-20260816.json`.

## Verdict

The source contains credible implementations for every major workflow area,
but none has yet been exercised as the complete identity-bound English THP
workflow required for submission. The gate therefore remains **NO-GO**.

| Area | Source finding | Submission status |
| --- | --- | --- |
| FIDE rating-list import | Admin-only HTTPS download from `ratings.fide.com`, TLS verification, temporary download publication and transactional 500-row batches exist | Partial; no source hash/version attestation, whole-import rollback or exact-candidate probe |
| Tournament/player/result workflow | Ownership checks, FIDE result codes, byes, withdrawals and round persistence exist | Partial; the bulk-result endpoint can commit a prefix, return `success=true` and report later row errors |
| Individual FIDE mode | Server-side profile locks Dutch/BBP6, disables manual pairing and verifies BBP6 plus Gacrux identities; rc.31 serializes and persists one initial-colour draw inside the atomic start boundary | Partial; OpenPairing is not a product route and the new migration/rollback/concurrency path has not run on isolated staging |
| Recovery | Deleted-tournament snapshots and several pairing paths use transactions | Partial; snapshot restore deliberately skips failed child rows and pairing/result recovery probes have not run on the exact candidate |
| TRF import/export | Individual TRF import is previewed, hash-bound, transactional and now records explicit/inferred/pending record-`152` provenance | Partial; the exact candidate still needs a database round trip and record `299` remains outside the lossless profile |
| English UI/manual | English is the default language and the English catalogue has 3,126 keys | NO-GO; 59 Italian catalogue keys have no English entry, the FIDE rating-import page is hard-coded Italian, and an English packaged operator manual is unproved |
| Public package/download | The rc.31 source archive is reproduced three ways, its Git bundle is off-site backed up and its notice matches rc.31 | NO-GO; metadata still says RC/not approved and no controlled final public download is present |

## Detailed findings

### Rating-list import

`import_fide.php` requires login and administrator privileges. The download
helper accepts only HTTPS on `ratings.fide.com`, verifies TLS and redirects,
writes to a random `.part-*` file and renames it only after size checks. The
XML batch importer uses an upsert and commits every 500 players.

These are good operational controls, but the submitted workflow still needs:

- a unique, locked import job rather than the shared `uploads/fide_import.zip`
  and `uploads/import_params.json` names;
- the source URL, retrieval time, byte count and SHA-256 in immutable evidence;
- explicit malformed/stale-file rejection and an idempotent reimport proof;
- a fail-closed whole-import policy or a precisely documented resumable
  transaction contract; row exceptions are currently counted and skipped;
- the identity-bound rating-import probe batch from the exact final product.

### Tournament, players, results and byes

The normal product exposes tournament ownership, player registration, FIDE
played/forfeit/bye result codes, scheduled absences, requested byes and
withdrawals. Pairing publication paths persist games, history, evidence and
round advancement inside transactions in important automatic paths.

The AJAX bulk-result endpoint is not atomic: it calls `inserisciRisultato()`
once per row, catches errors, continues and returns `success=true`. An
interruption or invalid later row can therefore leave a committed prefix. The
final workflow needs an all-or-nothing batch, an expected tournament revision,
duplicate/stale-submit protection, and failure-injection tests proving that
scores, history and standings remain aligned.

### FIDE mode and first-round identity

`FideModePolicy` enforces the individual Dutch profile, locks the selected
engine to BBP6, disables manual pairing, constrains rounds and acceleration,
and protects scoring. Tournament start verifies exact BBP6 and Gacrux
identities before changing state.

Rc.31 closes the two previously identified source gaps. It validates and
persists record-`152` provenance, takes a row lock before resolving a random
initial colour, and includes the colour write, TPN assignment, Baku boundary
and start-state mutation in one transaction or caller savepoint. The attested
colour is then immutable. The deterministic policy/codec/source test passes
21/21 on PHP 8.2 and PHP 8.3/GMP.

This remains a source checkpoint: migration 111 has not been applied and the
database-backed concurrent-start, failure-injection and rollback probes have
not run on an isolated rc.31 staging release. Those runtime probes, rather
than the former source implementation task, are now the blocker.

The rc.21 OpenPairing shadow coordinator remains synthetic and read-only. It
does not make OpenPairing a selectable production engine and must not be used
to claim a combined THP identity.

### TRF interchange

The individual importer computes the source SHA-256, uses a preview token and
explicit confirmation, rejects structural or unsupported modeled semantics,
and commits the tournament, players, history, games, tie-breaks, acceleration
and prohibited pairings within a transaction or savepoint. Legacy TRF06/16
requires an explicit Dutch-2026 interpretation.

Rc.31 makes record `152` provenance explicit in preview/import. A played
history without the record is inferred only from TPN 1 round 1 and otherwise
fails closed; an empty history exposes a pending one-time draw which must be
persisted at start. Invalid record values are rejected. A database-backed
import/export replay of the exact rc.31 archive is still required, while
record `299` and the declared legacy contract remain separate open scope
decisions.

### Recovery

Deleted-tournament snapshots can be restored within their retention window
inside a transaction. That is useful disaster recovery, but it is not proof
of pairing-transaction recovery. The restore code also logs and skips child
row conflicts, so a committed restore may be incomplete by design.

Submission evidence still needs the published recovery-probe schema executed
against the exact product: failure before engine, after engine, during evidence
write, during pairing write and before round advancement; every probe must
prove unchanged canonical state or a complete committed state.

### English interface and package

The language subsystem defaults to English and exposes seven PHP catalogues.
A mechanical catalogue comparison found 3,126 English keys and 3,185 Italian
keys: 59 keys present in Italian are absent from English. Most are tie-break
explanations, which are directly relevant to an arbiter workflow. The FIDE
rating-import page also emits Italian HTML and hard-coded Italian status/error
messages.

The exact final English workflow and manual therefore require both catalogue
completion and visual execution evidence. Source presence alone is not enough.

The frozen rc.31 source archive and bundle are sound internal checkpoints, not
a public package. Their metadata still says `release-candidate` and
`officialRecognition: none`; there is no final public download route,
signature/checksum page or controlled installer. The third-party notice now
matches rc.31 but must be regenerated again for the final identity.

## Required closure order

1. Apply migration 111 only to isolated staging and prove concurrent start,
   failure injection and complete rollback on the exact rc.31 archive.
2. Make bulk result submission atomic and revision-bound; execute the operator
   and recovery probe batches.
3. Attest rating-list source identity and prove idempotent, malformed, stale
   and rollback cases.
4. Execute an exact-candidate database TRF round trip and retain the explicit
   record-`152` provenance evidence.
5. Complete the English catalogue and run the complete English GUI/manual
   workflow on the exact candidate.
6. Connect the already-frozen OpenPairing shadow observer only in an isolated
   authorised environment, then collect a denominator-preserving cohort.
7. Freeze a non-RC combined identity and publish the controlled package,
   notices, checksums and download only after all identity-dependent gates
   reproduce.

Until those steps and the other readiness gates close, the correct external
statement remains: **OpenPairing has substantial engine and harness evidence,
but ChessPairings/OpenPairing is not yet ready for official FIDE submission.**
