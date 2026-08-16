# ChessPairings product-workflow audit

This is a source-level audit of the isolated ChessPairings Web candidate
`5.21.0-rc.21`, commit
`0544d6b65db547060d6a5563af2ff0085b803241`. It was performed on 16 August
2026 without connecting to a product database, staging or production. The
candidate is not FIDE approved or endorsed and this audit does not close the
`complete-thp-workflow` gate.

The inspected source is useful: it narrows the remaining work from a generic
"complete product" requirement to concrete, testable product gaps. Findings
and exact inspected-file hashes are frozen in
`evidence/chesspairings-product-workflow-audit-20260816.json`.

## Verdict

The source contains credible implementations for every major workflow area,
but none has yet been exercised as the complete identity-bound English THP
workflow required for submission. The gate therefore remains **NO-GO**.

| Area | Source finding | Submission status |
| --- | --- | --- |
| FIDE rating-list import | Admin-only HTTPS download from `ratings.fide.com`, TLS verification, temporary download publication and transactional 500-row batches exist | Partial; no source hash/version attestation, whole-import rollback or exact-candidate probe |
| Tournament/player/result workflow | Ownership checks, FIDE result codes, byes, withdrawals and round persistence exist | Partial; the bulk-result endpoint can commit a prefix, return `success=true` and report later row errors |
| Individual FIDE mode | Server-side profile locks Dutch/BBP6, disables manual pairing and verifies BBP6 plus Gacrux identities | Partial; OpenPairing is not a product route, the initial random colour is not frozen at start, and start mutations are not one transaction |
| Recovery | Deleted-tournament snapshots and several pairing paths use transactions | Partial; snapshot restore deliberately skips failed child rows and pairing/result recovery probes have not run on the exact candidate |
| TRF import/export | Individual TRF import is previewed, hash-bound, fail-closed on modeled semantics and transactional; FIDE export is canonical | Partial; absent record `152` is silent and becomes `random`, export may omit `152`, record `299` remains outside the lossless profile |
| English UI/manual | English is the default language and the English catalogue has 3,126 keys | NO-GO; 59 Italian catalogue keys have no English entry, the FIDE rating-import page is hard-coded Italian, and an English packaged operator manual is unproved |
| Public package/download | The isolated bundle is reproducible and off-site backed up | NO-GO; `release.json` says RC/not approved, no public final download is present, and the bundled third-party notice still identifies rc.6 |

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

Two gaps remain material:

1. individual start leaves `initial_color=random`; TRF generation can draw a
   new value before a first round is saved, so retries do not have one stable
   canonical input;
2. start assigns TPNs, freezes Baku state and changes tournament state through
   separate mutations rather than one rollback boundary.

The team FIDE path already demonstrates the intended shape by resolving and
persisting the initial colour before pairing. The individual implementation
should adopt an equivalent one-time persisted value, within the same start
transaction, before a final candidate is frozen.

The rc.21 OpenPairing shadow coordinator remains synthetic and read-only. It
does not make OpenPairing a selectable production engine and must not be used
to claim a combined THP identity.

### TRF interchange

The individual importer computes the source SHA-256, uses a preview token and
explicit confirmation, rejects structural or unsupported modeled semantics,
and commits the tournament, players, history, games, tie-breaks, acceleration
and prohibited pairings within a transaction or savepoint. Legacy TRF06/16
requires an explicit Dutch-2026 interpretation.

Record `152` is optional in the codec. If absent, the importer silently stores
`initial_color=random`; the canonical exporter may then omit `152` when the
first colour cannot be inferred. This is not a pairing-engine defect, but it
is an operator/input-identity gap. Preview and import must report the origin of
the value (`record 152`, inferred from a played first board, or explicit
override) and fail closed when it cannot be determined for a state that needs
replay. Record `299` and the declared legacy contract remain separate open
scope decisions.

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

The frozen rc.21 bundle is a sound internal checkpoint, not a public package.
Its metadata still says `release-candidate` and `officialRecognition: none`;
there is no final public download route, signature/checksum page or controlled
installer. `docs/THIRD_PARTY_NOTICES_EN.md` still names rc.6 and must be
regenerated for the final identity.

## Required closure order

1. Freeze the one-time individual initial colour and make tournament start
   atomic; add negative and rollback tests.
2. Make bulk result submission atomic and revision-bound; execute the operator
   and recovery probe batches.
3. Attest rating-list source identity and prove idempotent, malformed, stale
   and rollback cases.
4. Make missing TRF `152` provenance explicit and fail closed when neither
   record, inference nor operator override is available.
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
