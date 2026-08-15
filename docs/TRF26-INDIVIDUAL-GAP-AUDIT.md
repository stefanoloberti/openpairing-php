# TRF26 individual gap audit

Audit date: 2026-08-15. Normative input: the locally preserved FIDE
`TRF-2026.pdf`. This is a scope ledger, not a claim that every listed record is
mandatory in every tournament.

## Confirmed current coverage

The RC8 public codec covers the core tournament section, player section, exact
individual scoring, Dutch/Burstein/Round-Robin identification, rank-order
records, time control, conditional `142`/`152` and individual acceleration
record `250`. It also closes individual scheduled byes (`240`) and
round-ranged prohibited pairings (`260`) end to end. See `TRF-2026.md` for
exact fields and limits.

## Remaining individual records

| Record | Normative purpose | Why it matters | RC8 status | Closure evidence |
|---|---|---|---|---|
| `240` | Full-, half- and zero-point byes; mandatory in ITDX for future rounds when applicable | A THP/engine exchange before pairing must exclude declared bye recipients without fabricating a result | Implemented | Fixed-column round trip, canonical-state round trip, unknown/conflicting/completed-history negative controls, and next-round engine exclusion |
| `260` | Round-ranged groups of players who cannot meet | It changes legal pairing edges and therefore cannot be silently ignored | Implemented | Fixed-column and canonical round trips, IDs/ranges validation, Dutch/Burstein enforcement, solvable/no-solution tests and historical PTC replay |
| `299` | Individual bonus or penalty points when type is blank | It can change standings and pairing scores independently of game results | Rejected | Still requires exact adjustments, reported-score reconciliation, correct round/all-round scope and tests for pairing plus every affected tie-break |
| NRS records plus `172` | Federation national-rating data and initial-rank method | Required only when the submitted product claims national-rating support | Outside engine scope | Either implement the declared federation workflow in the THP or explicitly exclude it with TEC acceptance |

## Records not part of the individual engine claim

Records `013`, `300`, `310`, `320`, `330`, `352`, `362`, `801` and `802` are
team records. They remain outside the individual OpenPairingPHP release and
must not be cited as missing individual-engine behavior. Team support, if
submitted later, requires a separate product identity and acceptance matrix.

## Decision rule

Do not add a record merely so the parser accepts it. Record `299` affects
tournament state and standings and is complete only when its
semantics reach the pairing/standings output and negative controls. Any such
source change creates a new candidate identity; the official corpus queue must
then be restarted or explicitly retained as evidence for the older component
identity, never relabelled.
