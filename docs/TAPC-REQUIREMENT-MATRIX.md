# TAPC requirement matrix

Checked on 2026-08-16 against the FIDE rules effective from 1 March 2026.
This is a working response matrix for owner and TEC review. It is not an
application, a certification claim, or evidence that OpenPairingPHP by itself
is a complete Tournament Handler Program (THP).

Normative source: FIDE Handbook, *Chess Equipment with Electronic Components*,
Articles 7.1-7.3:
<https://handbook.fide.com/chapter/ChessEquipmentWithElectronicComponenets032026>.
The current application route is linked from the FIDE TEC Online Forms:
<https://www.cognitoforms.com/FIDE/TECMenu>.

Status vocabulary:

- **closed**: hash-pinned evidence exists for the stated component scope;
- **partial**: an implementation or release-candidate control exists, but the
  exact submitted product has not closed the requirement;
- **open**: technical evidence is still required;
- **external**: TEC or owner action is required and cannot be manufactured by
  the repository.

## Product boundary

The FIDE definition in Article 7 describes a THP as software that manages
Swiss tournaments, including player data, pairings, and results. OpenPairingPHP
is presently a pairing/standings library with offline CLI, PTC and RTG tools;
it is not that complete workflow. Unless TEC confirms a separate route in
writing, the exact submitted target must therefore be a ChessPairings THP that
embeds this engine. Evidence for the library and evidence for another product
identity must not be combined silently.

## Conditions of compliance

| Article | Requirement, paraphrased | Current evidence | Status | Exact closure condition |
|---|---|---|---|---|
| 7.1.1(a) | Import current FIDE rating lists | Outside the library boundary | open | Demonstrate update/import, reconciliation, and failure handling in the exact submitted THP |
| 7.1.1(b) | Implement at least one declared FIDE Swiss system and every FIDE-defined acceleration method | Dutch and Burstein engines; Baku implementation; immutable corpus workflow | partial | Close Dutch and accelerated corpus gates under the final identity, then exercise the packaged THP path |
| 7.1.1(c) | Produce final standings using every mandatory FIDE tie-break | Exact-arithmetic individual tie-break implementation and PTC | partial | Publish final-package PTC evidence and verify the exact THP standings workflow |
| 7.1.2 | Declare the pairing systems for which compliance is requested | Proposed scope: individual Dutch; Burstein retained outside the initial claim until owner/TEC decision | external | Freeze the declaration in the application and product FIDE mode |
| 7.1.3(a) | Provide an English FIDE-mode interface and complete user help/manual | English engine CLI and `USER-MANUAL.md` | partial | Document and test the complete English THP operator workflow |
| 7.1.3(b), 7.2.1-7.2.2 | Import/export the latest TRF and retain required older-format compatibility | Fail-closed individual TRF26 subset; legacy `XXA` acceleration import | partial | Resolve record `299`, document the declared subset, and prove the TEC-required TRF16/TRF06 paths |
| 7.1.3(c), 7.2.3 | Make a free embedded PTC available using the product engines | Public offline `openpairing-ptc.php` and fixtures | partial | Reproduce PTC from the exact final THP distribution and confirm acceptable embedding with TEC |
| 7.1.3(c), 7.2.4 | Make a free embedded configurable RTG available | Public deterministic `openpairing-rtg.php` and RTG-to-PTC control | partial | Reproduce RTG and round trip from the exact final THP distribution |
| 7.1.3(d) | Supply a controlled tester environment | Reproducible engine runtime instructions for Windows limbs and Ubuntu GMP | partial | Freeze a TEC-accessible environment for the exact submitted THP |
| 7.1.4 | Extra features must not compromise declared FIDE systems | Engine/CLI fail-closed controls; provisional public API freeze | partial | Test FIDE-mode isolation and non-bypass behavior in the exact THP |

## Acceptance and administrative controls

| Control | Current status | Closure condition |
|---|---|---|
| Exact submitted version and identity | RC8 is reproducible; no stable product identity selected | Freeze one non-RC THP/source/runtime identity after technical gates close |
| Current TEC route and checklist | Scope enquiry drafted but not sent | Owner supplies legal/vendor/contact data, approves the enquiry, and TEC confirms the route |
| Forms, fees and tester coordination | Not started | Use the current TEC forms and administrative instructions; do not submit before technical sign-off |
| Public claims | Repository states NO-GO while required gates are open | Keep all wording limited to implemented/verified component evidence until FIDE issues a decision |

## Evidence map

- `FIDE-SUBMISSION-READINESS.md`: fail-closed readiness ledger and blockers.
- `SUBMISSION-ARCHITECTURE.md`: product-identity boundary and sequencing.
- `TEC-SCOPE-ENQUIRY-DRAFT.md`: owner-review questions for TEC.
- `CORPUS-GATE.md`: immutable corpus identities and partition status.
- `TRF26-INDIVIDUAL-GAP-AUDIT.md`: declared codec surface and record `299` decision.
- `SHADOW-VALIDATION.md`: non-mutating exact-product observation gate.
- `CONTROLLED-ENVIRONMENT.md`: release-candidate reproduction boundary.
- `PACKAGING.md` and `RELEASE.md`: distribution and stable-identity gate.
- `schema/submission-readiness-0.9.json`: machine-readable authoritative
  readiness status; `php tools/verify_submission_readiness.php` must continue
  to report `NO_GO` until every required gate is genuinely closed.

