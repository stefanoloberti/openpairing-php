# FIDE submission readiness

Audited on 2026-08-15 against the FIDE rules effective in 2026. This document
is a fail-closed readiness ledger, not a certification claim.

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
No public claim should describe OpenPairingPHP alone as TAPC-ready.

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

The older C.04.A/FE-1 and VCL19 documents remain useful historical controls,
but they must not be assumed to replace the current TEC forms or TAPC process.

## Readiness matrix

| Requirement | Engine/package evidence | Product-level status | Closure condition |
|---|---|---|---|
| Versioned product | Semantic RC version and deterministic source/runtime archives | Partial | Freeze a non-RC candidate and identify the exact ChessPairings product version |
| FIDE Dutch 2026 | Public Dutch engine, validators, immutable corpus campaign | Open gate | Close all identity-scoped corpus partitions and classify every non-exact case |
| All FIDE acceleration methods | Baku implementation, TRF `250`, late-entry identity | Open gate | Close the 1,000-tournament accelerated partition under final identity |
| Mandatory individual tie-breaks | All MTB26 individual descriptors and variants implemented with exact arithmetic | Engine complete; product gate open | Publish a PTC tie-break report and verify the ChessPairings standings path |
| FIDE rating-list import | Not an engine responsibility | Missing evidence | Prove import/update through the submitted ChessPairings build |
| English interface and manual | English CLI and technical docs | Partial | Provide complete English THP user manual/help and FIDE-mode workflow |
| FIDE mode | CLI exposes only declared FIDE engines but has no named THP mode | Missing product evidence | Make FIDE mode default or explicit in ChessPairings and test prohibited features are isolated |
| Latest TRF import/export | Fail-closed individual TRF26 subset; conditional `142`/`152`, scheduled forfeits and unusual results covered | Partial | Complete the THP-required TRF26 individual surface and backward-compatibility tests; decide any TEC-required TRF16 scope |
| Pairing and Tie-Break Checker (PTC) | Public offline `openpairing-ptc.php`, non-vacuous JSON report, embedded Dutch/Burstein replay and declared-rank checks | Implemented; release gate open | Include it in the frozen runtime, publish positive/negative reports and verify the ChessPairings download path |
| Random Tournament Generator (RTG) | Public offline `openpairing-rtg.php`; deterministic seed; Dutch/Burstein, colour, draw-rate and Baku parameters; complete TRF26 output | Implemented; release gate open | Include it in the frozen runtime and publish byte-reproducibility plus PTC round-trip reports |
| Controlled test environment | Immutable internal validation releases exist | Missing public deliverable | Publish a reproducible container/VM or equivalent TEC-accessible environment for the exact candidate |
| Player/result/tournament management | Outside this library | Missing engine-alone | Demonstrate the full ChessPairings THP workflow with player data, results, byes and recovery |
| Online forms, fees and manufacturer registration | Not a code task | Not started | Register manufacturer, obtain current forms/fees, complete SDPC/TAPC inputs without submitting before technical sign-off |
| Council approval / endorsement | External FIDE decision | Not applicable yet | TAPC testing and approval first; endorsement additionally requires a FIDE commercial agreement |

## Current technical blockers in order

1. Close the immutable Dutch corpus queue (`p80`, `p150`, `misc`) and the
   accelerated queue without mixed identities.
2. Freeze and publish the implemented PTC and RTG in the final runtime, with
   positive/negative machine-readable examples and a deterministic RTG-to-PTC
   round trip.
3. Audit the complete ChessPairings product for rating-list import, English
   FIDE mode, result/byes workflow, recovery, TRF import/export and public
   download packaging.
4. Freeze one non-RC source/product/runtime identity, reproduce all gates from
   it, and assemble the controlled environment plus English manual.
5. Only then populate the live TEC forms and pay testing fees.

## Submission evidence package

The final package should contain:

- exact product, engine, source-tree and runtime hashes;
- signed/checksummed source and runtime downloads;
- operating-system and PHP/GMP requirements;
- English installation, FIDE-mode, PTC and RTG manuals;
- PTC and RTG commands with deterministic positive and negative examples;
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
