# FIDE submission readiness

Audited on 2026-08-16 against the FIDE rules effective in 2026. This document
is a fail-closed readiness ledger, not a certification claim.

The machine-readable companion is
`schema/submission-readiness-0.9.json`. Run
`php tools/verify_submission_readiness.php`; exit `1` and status `NO_GO` are
the expected result while any required gate remains open or external. A gate
may be changed to `closed` only with existing hash-pinned evidence. Missing or
changed evidence is an infrastructure error (exit `2`), preventing a prose
update from manufacturing a submission-ready verdict.
The first executable checkpoint of this ledger is preserved in
`evidence/submission-readiness-ledger-checkpoint-20260816.json`; it validates
the fail-closed behavior and records `NO_GO`, without closing a corpus or
product gate.

## Correct submission target

The controlling process from 1 March 2026 is the FIDE Technical Commission
Technical Acceptance of Product Compliance (TAPC), not only the historical
FE-1/SPPC procedure. The applicant product class is a Tournament Handler
Program (THP), historically called a pairing program. A THP manages player
data, pairings and results on a commercially available operating system or an
accepted online platform.

OpenPairingPHP is currently an offline pairing and standings engine plus CLI,
not a complete THP. Therefore one of these must be resolved before filing:

1. submit the complete ChessPairings THP with OpenPairingPHP named as its
   embedded pairing engine; or
2. obtain written TEC confirmation that the engine can be assessed as a
   separate product and identify the applicable form/checklist.

Until TEC confirms option 2, option 1 is the working submission architecture.
However, the inspected ChessPairings Desktop checkpoint does not currently
embed OpenPairingPHP. Integrating it would create a new candidate identity and
invalidate exact-product evidence, so it is not an automatic continuation of
the current Desktop dossier. The sequencing decision and an owner-review TEC
enquiry are in `SUBMISSION-ARCHITECTURE.md` and
`TEC-SCOPE-ENQUIRY-DRAFT.md`. No public claim should describe OpenPairingPHP
alone as TAPC-ready.

## Normative sources

- FIDE General Regulations for product acceptance, effective 1 March 2026:
  <https://handbook.fide.com/chapter/GeneralRulesAndRegulations032026>
- FIDE technical specifications for THPs, Articles 7.1-7.3, effective 1 March
  2026:
  <https://handbook.fide.com/chapter/ChessEquipmentWithElectronicComponenets032026>
- TRF26:
  <https://handbook.fide.com/files/handbook/TRF26.pdf>
- Mandatory Tie-Breaks 2026:
  <https://handbook.fide.com/files/handbook/MTB26.pdf>
- FIDE-approved acceleration methods:
  <https://handbook.fide.com/chapter/C0407202602>
- Current online application entry point:
  <https://www.cognitoforms.com/FIDE/TECMenu>
- FIDE TEC Policies and Procedures Manual 1.24, dated 7 March 2026:
  <https://tec.fide.com/wp-content/uploads/2026/03/FIDE-TEC-Manual-01.24-1.pdf>

The 2026-08-16 read-only source check and the remaining interpretation
questions are recorded in
`evidence/fide-normative-scope-check-20260816.json`. No form or enquiry was
submitted by that check.

The article-by-article working response, with explicit component/product
boundaries and closure conditions, is in `TAPC-REQUIREMENT-MATRIX.md`.

The older C.04.A/FE-1 and VCL19 documents remain useful historical controls,
but they must not be assumed to replace the current TEC forms or TAPC process.

## Readiness matrix

| Requirement | Engine/package evidence | Product-level status | Closure condition |
|---|---|---|---|
| Versioned product | Semantic RC version and deterministic source/runtime archives | Partial | Freeze a non-RC candidate and identify the exact ChessPairings product version |
| FIDE Dutch 2026 | Public Dutch engine, validators, immutable corpus campaign | Open gate | Close all identity-scoped corpus partitions and classify every non-exact case |
| All FIDE acceleration methods | Baku implementation, TRF `250`, late-entry identity | Open gate | Close the 1,000-tournament accelerated partition under final identity |
| Mandatory individual tie-breaks | All MTB26 individual descriptors and variants implemented with exact arithmetic | Engine complete; product gate open | Publish a PTC tie-break report and verify the ChessPairings standings path |
| FIDE rating-list import | Not an engine responsibility; exact-candidate probe schema and verifier ready | Missing product evidence | Prove current import, reconciliation, idempotent reimport, stale/malformed rejection and rollback through the submitted ChessPairings build |
| English interface and manual | English CLI plus `USER-MANUAL.md`; identity-bound full-workflow evidence gate ready | Partial | Execute the complete English THP GUI/FIDE-mode workflow and visually review its packaged help on the exact submitted product |
| FIDE mode | CLI exposes only declared FIDE engines but has no named THP mode | Missing product evidence | Make FIDE mode default or explicit in ChessPairings and test prohibited features are isolated |
| Latest TRF import/export | Fail-closed individual TRF26 subset; conditional `142`/`152`, scheduled forfeits, unusual results, future individual byes (`240`) and prohibited-pairing groups (`260`) covered; official legacy layouts audited | Partial | Decide record `299` and implement or obtain TEC acceptance for the explicit TRF16/TRF06 contract in `LEGACY-TRF-COMPATIBILITY.md` |
| Pairings and Tie-Breaks Checker (PTC) | Public offline `openpairing-ptc.php`, non-vacuous JSON report, embedded Dutch/Burstein replay, declared-rank checks and published positive/negative fixtures | Implemented; release/cross-test gate open | Reproduce it from the exact THP package, then process the TEC-required 50,000 tournaments from an external available RTG and classify every discrepancy |
| Random Tournament Generator (RTG) | Public offline `openpairing-rtg.php`; deterministic seed; Dutch/Burstein, colour, draw-rate and Baku parameters; complete TRF26 output; byte-stable RTG-to-PTC control published | Implemented; release/cross-test gate open | Reproduce it from the exact THP package, generate the TEC-required 50,000 tournaments and have one or more available PTCs verify them |
| Controlled test environment | Reproducible engine ZIP procedure and two arithmetic profiles in `CONTROLLED-ENVIRONMENT.md` | Partial | Freeze and publish a TEC-accessible environment for the exact non-RC THP candidate |
| Player/result/tournament management | Outside this library; chained synthetic operator-workflow gate ready | Missing engine-alone | Produce the nine-step identity-bound workflow batch from the exact ChessPairings THP and close its dedicated transaction/recovery probes |
| Online forms, fees and manufacturer registration | Not a code task | Not started | Register manufacturer, obtain current forms/fees, complete SDPC/TAPC inputs without submitting before technical sign-off |
| Council approval / endorsement | External FIDE decision | Not applicable yet | TAPC testing and approval first; endorsement additionally requires a FIDE commercial agreement |

## Current technical blockers in order

1. Close the immutable Dutch corpus queue (`p80`, `p150`, `misc`) and the
   accelerated queue without mixed identities.
2. Freeze and publish the implemented PTC and RTG in the final runtime, with
   positive/negative machine-readable examples and a deterministic RTG-to-PTC
   round trip. Then close the two distinct 50,000-tournament cross-tests in TEC
   Manual 1.24 Article 3.9.4: external RTG to candidate PTC, and candidate RTG
   to one or more available PTCs.
3. Implement the non-mutating product observer defined in
   `SHADOW-VALIDATION.md` once an exact integrated candidate is selected; the
   pairing API inventory is frozen provisionally in `API-STABILITY.md`. An
   isolated ChessPairings rc.21 candidate now has an executable TRF-to-engine
   mapping, complete incumbent comparator, immutable request, portable stale
   revision/hash guard, versioned privacy-minimised event, atomic event sink and
   pre-execution immutable attempt reservation. Its isolated coordinator also
   proves that stale or unreadable post-compute snapshots leave the attempt in
   the denominator without an event. The candidate-side primitives are
   source-frozen and portable-tested against the same runtime/engine identity
   consumed by the denominator join. The live database revision reread,
   isolated product call site, owner/privacy review and transaction probes
   remain product work; none of this enables production. Rc.31 carries those
   primitives forward without connecting a live route.
4. The source-level audit is frozen in
   `CHESSPAIRINGS-PRODUCT-WORKFLOW-AUDIT.md`. Rc.31 now closes the source
   implementation for record-`152` provenance, a row-locked one-time initial
   colour and atomic tournament start; its source archive is reproduced three
   ways and is not deployed. The gate remains open because migration 111 and
   concurrent-start/rollback probes have not run on isolated staging, while
   rating-import attestation, recovery probes, complete English UI/manual and
   a public final package also remain required. Rc.32 now implements atomic,
   round-bound and per-board revision-bound result batches in source, but its
   concurrent-submit and rollback behavior still needs isolated-staging proof.
   Rc.33 reaches complete Italian-to-English catalogue-key parity. Rc.34
   translates the rating-import page, rc.35 removes its embedded browser key,
   rc.36 packages an identity-bound English operator manual, and rc.37
   isolates each administrator's rating-import workspace behind POST/CSRF,
   an owner/session run token, one non-blocking lock, validated basenames and
   atomically promoted progress. The exact rc.37 source freeze passes with
   3,249/3,249 catalogue keys, but disposable-database import/concurrency/
   rollback probes, English visual execution and owner-side browser-key
   rotation remain open. The complete product gate stays `NO_GO`.
5. Freeze one non-RC source/product/runtime identity, reproduce all gates from
   it, and assemble the controlled environment plus English manual.
6. Only then populate the live TEC forms and pay testing fees.

The historical 122,008-tournament replay and the official PTC/RTG cross-tests
answer different questions. The former verifies behavior against preserved
TRF reference rounds; it must not be reported as if it fulfilled either
50,000-tournament TEC cross-test. Likewise, an internal RTG-to-PTC round trip
is a non-vacuity/reproducibility control, not an independent cross-check.

The administrative order is also identity-sensitive: the TEC Manual requires
a successful SDPC before TAPC and says TAPC can cover only the exact product
version that received the SDPC. Freezing or rebuilding after SDPC therefore
requires owner/TEC handling rather than relabelling evidence.

The strict batch/event format and fail-closed local verifier for preserving
those future cross-tests are documented in `TEC-CROSS-TEST.md`. Tooling
readiness does not close either external cross-test.

The exact-candidate execution order, atomic report-promotion rule and abort/
resume boundary for all THP evidence are fixed in
`THP-EVIDENCE-EXECUTION-RUNBOOK.md`.

## Submission evidence package

The final package should contain:

- exact product, engine, source-tree and runtime hashes;
- signed/checksummed source and runtime downloads;
- operating-system and PHP/GMP requirements;
- English installation, FIDE-mode, PTC and RTG manuals;
- PTC and RTG commands with deterministic positive and negative examples;
- a value-redacting source/installer/runtime secret-scan report, followed by
  private review and rotation of every finding before the candidate freeze;
- an identity-bound runtime-configuration batch proving external injection,
  least-privilege scope, fail-closed missing/scope handling and zero exposure;
- complete corpus, acceleration, capacity and tie-break reports with preserved
  denominators and classifications;
- controlled-environment reproduction steps;
- a requirement-by-requirement VCL/TAPC response with links to evidence;
- an explicit limitations statement covering individual/team scope and every
  unsupported TRF record.

## Go/no-go rule

The project is **NO-GO for submission** while any matrix row marked Missing or
Open gate remains. Engine correctness evidence may continue to accumulate, but
it cannot substitute for THP-level product requirements or the current TEC
administrative process.
