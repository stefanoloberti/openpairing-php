# Submission status lanes

This document keeps three related but non-interchangeable work streams
separate. It is a reporting map, not a gate and not evidence by itself. The
linked documents and exact machine-readable reports remain authoritative.

## Lane A: OpenPairingPHP component and corpus

**Identity:** the PHP library and its public pairing, PTC and RTG CLIs.

**Current evidence:** reproducible RC8 packages, frozen public API, historical
p7-p50 corpus closure, and the active identity-bound p80 campaign described in
`CORPUS-GATE.md`.

**Open work:** p80 and later Dutch partitions, accelerated corpus, final
external PTC/RTG cross-tests, and a stable non-RC identity. Capacity pilots are
reported separately from the supported production range.

**Permitted claim:** deterministic release candidate with closed component
checks and explicitly listed open gates.

**Forbidden claim:** complete-corpus, final-release or FIDE-approved status.

## Lane B: ChessPairings THP candidate

**Identity:** the exact isolated ChessPairings candidate named by each source
freeze and product checkpoint. It is not the OpenPairingPHP package and it is
not the public production deployment.

**Current evidence:** isolated source and bundle checkpoints through the RC38
rating-import ZIP boundary, plus fail-closed schemas and verifiers for pairing,
transactions, recovery, mode, performance, rating import, operator workflow
and runtime configuration. See `THP-PRODUCT-ACCEPTANCE-PLAN.md` and
`FIDE-SUBMISSION-READINESS.md`.

**Open work:** execute the complete identity-bound GUI/product evidence plan,
close the source/product/runtime freeze, perform privacy and owner review, and
rotate the previously exposed browser key through the owning provider account.
The rotation is an owner action; a source-code cleanup cannot prove it.

**Permitted claim:** isolated candidate engineering and verifier checkpoints.

**Forbidden claim:** production deployment, complete THP acceptance or reuse
of OpenPairing corpus counts as ChessPairings product evidence.

## Lane C: TEC scope and submission procedure

**Identity:** the applicant, selected product class, exact submitted version
and the forms/checklist confirmed by FIDE TEC.

**Current evidence:** the architecture decision, requirement matrix and
owner-review enquiry draft in `SUBMISSION-ARCHITECTURE.md`,
`TAPC-REQUIREMENT-MATRIX.md` and `TEC-SCOPE-ENQUIRY-DRAFT.md`.

**Open work:** owner approval and sending of the enquiry, written TEC response,
selection of the actual submission route, legal/vendor/contact fields, and any
provider or account actions. These cannot be inferred from technical tests.

**Permitted claim:** submission preparation is in progress and its technical
blockers are explicitly tracked.

**Forbidden claim:** that TEC has accepted a product scope, waived a
requirement or approved the software before written confirmation.

## Reporting rule

Every status email or handoff should present the lanes in this order and state
the identity, evidence delta, blocker and next action for each lane. A result
from one lane may be linked as supporting context for another, but it cannot
close that other lane's gate. The aggregate submission state remains the
machine-verified result of `docs/schema/submission-readiness-0.9.json`.
