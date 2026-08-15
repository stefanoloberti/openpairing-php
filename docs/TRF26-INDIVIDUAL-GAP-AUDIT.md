# TRF26 individual gap audit

Audit date: 2026-08-15. Normative input: the locally preserved FIDE
`TRF-2026.pdf`. This is a scope ledger, not a claim that every listed record is
mandatory in every tournament.

## Confirmed current coverage

The public codec covers the core tournament section, player section, exact
individual scoring, Dutch/Burstein/Round-Robin identification, rank-order
records, time control, conditional `142`/`152` and individual acceleration
record `250`. See `TRF-2026.md` for exact fields and limits.

## Remaining individual records

| Record | Normative purpose | Why it matters | RC7 status | Closure test |
|---|---|---|---|---|
| `240` | Full-, half- and zero-point byes; mandatory in ITDX for future rounds when applicable | A THP/engine exchange before pairing must exclude declared bye recipients without fabricating a result | Rejected | Decode/encode future-round individual byes, expose them in canonical state and prove the next-round engine excludes them while preserving audit output |
| `260` | Round-ranged groups of players who cannot meet | It changes legal pairing edges and therefore cannot be silently ignored | Rejected | Decode/encode constraints, validate IDs/ranges, enforce them in Dutch and Burstein including round one, and add solvable/no-solution tests |
| `299` | Individual bonus or penalty points when type is blank | It can change standings and pairing scores independently of game results | Rejected | Represent exact adjustments, reconcile reported scores, apply them at the correct round/all-round scope and test pairing plus every affected tie-break |
| NRS records plus `172` | Federation national-rating data and initial-rank method | Required only when the submitted product claims national-rating support | Outside engine scope | Either implement the declared federation workflow in the THP or explicitly exclude it with TEC acceptance |

## Records not part of the individual engine claim

Records `013`, `300`, `310`, `320`, `330`, `352`, `362`, `801` and `802` are
team records. They remain outside the individual OpenPairingPHP release and
must not be cited as missing individual-engine behavior. Team support, if
submitted later, requires a separate product identity and acceptance matrix.

## Decision rule

Do not add a record merely so the parser accepts it. Records `240`, `260` and
`299` affect tournament state or legality and are complete only when their
semantics reach the pairing/standings output and negative controls. Any such
source change creates a new candidate identity; the official corpus queue must
then be restarted or explicitly retained as evidence for the older component
identity, never relabelled.

