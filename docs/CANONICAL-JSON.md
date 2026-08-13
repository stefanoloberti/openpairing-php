# Canonical JSON v1

`openpairing.tournament/1` is the lossless project-owned boundary format. Exact
scores use `{ "units": n, "scale": d }`, meaning `n / d`; JSON numbers with a
fractional part are never accepted for regulatory decisions.

The decoder is fail-closed: maximum input size is 1 MiB, JSON depth is 32,
maximum players are 4,096; rounds and round-indexed list lengths are 256. Unknown properties, missing
required properties and schema/version/type mismatches produce a structured
diagnostic code. Strings are byte-bounded. Player IDs and tournament pairing
numbers are distinct and must each be unique.

The formal schema is `docs/schema/tournament-v1.schema.json`. The PHP decoder
also enforces semantic invariants that JSON Schema cannot express. Decode and
encode both reject non-reciprocal results, incompatible colours, repeated
opponents, score/history mismatches and a PAB flag inconsistent with results.
Fields for opponent, colour, result, bye, float, acceleration, team and club
histories are present for both pairing engines and tie-break evaluation.
`score` is always the standings score obtained from completed results;
`acceleration` is the current non-negative virtual-point amount. Pairing code
uses their exact sum, while final standings and tie-break calculations exclude
the virtual component.

`PairingResult` is emitted as JSON by the CLI and includes
`floatAssignments`, a deterministic list of `{playerId, direction}` entries
for every cross-scoregroup table. A pairing-allocated bye is explicitly listed
as `down`, including in the initial round; same-scoregroup players are
not listed and receive `none`. Live state updaters must consume this list. For
arbitrary entered results, `DutchFloatClassifier` is the canonical shared rule:
an unplayed allocation worth more than the configured loss score is `down`.

Optional v1 fields added for lossless supported TRF exchange are tournament
name; loss, zero-point-bye, PAB and unknown-result scores; and player sex,
federation, FIDE ID, birth date and reported rank. Their absence retains the
original v1 defaults, so the schema version did not change.
