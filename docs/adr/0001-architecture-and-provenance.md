# ADR 0001: ports-and-adapters core with canonical exact-score model

Status: accepted for milestone 1, 2026-08-11.

The pairing core is a framework-free PHP library. Input formats and development
oracles are adapters around immutable-leaning domain values. Decisions use an
exact `Score` represented as integer units plus a positive scale; binary floats
never enter pairing comparisons.

Canonical JSON is project-owned, versioned as `openpairing.tournament/1`, and
is not a copy of ChessJSON or TRF. Unknown versions, missing required values,
duplicate IDs/TPNs, unsafe sizes, and non-initial states sent to the initial
engine fail closed with structured error codes.

The first vertical slice implements the uniquely simple Dutch first-round
construction from official FIDE rules. It is original PHP, verified against a
local Gacrux oracle. Later porting will be semantic, file-by-file, with explicit
provenance. Upstream executables and Python are never runtime dependencies.

Apache-2.0 is the project licence. MIT notices remain attached to any future
Gacrux-derived substantial portions. BBP work remains Apache-2.0 with modified
file notices. This assessment is technical and should be legally reviewed
before distribution containing substantial ports.

