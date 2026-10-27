# FIDE individual standings and tie-breaks

`FideTieBreakCalculator`, `FideTieBreakRanker` and
`TieBreakDescriptorParser` implement the individual methods in the FIDE
Play-Off and Tie-Break Regulations effective 1 March 2026 and the TRF26 Rank
Order Descriptor syntax.

## Coverage

All 17 individual methods required by the FIDE Mandatory Tie-Breaks document
are implemented. The three additional individual descriptors in the Handbook
(`STD`, `TPN`, `RTNG`) and the TRF standings key `PTS` are implemented too.
That is 21 base codes. Counting canonical option combinations produces more
than 50 accepted descriptors (and Koya's half-point limit is parameterized),
so the project reports base methods and variants separately rather than
marketing an arbitrary “28 tie-breaks” count.

| Code | Definition | Supported variants |
|---|---|---|
| `PTS` | tournament points (TRF 212 ordering key) | none |
| `DE` | Direct Encounter, recursive subsets and Swiss guaranteed-rank rule | `/P` |
| `WIN`, `WON` | wins; games won over the board | none |
| `BPG`, `BWG` | games played/won with Black | none |
| `PS` | sum of progressive scores | `/C1`, `/C2` |
| `REP` | rounds elected to play | none |
| `STD` | standard 1/0.5/0 score | none |
| `TPN` | Tournament Pairing Number, ascending | none |
| `BH` | Buchholz | `/C1`, `/C2`, `/M1`, `/M2`, each optionally with `/P` |
| `AOB` | average of opponents' Buchholz | `/F` |
| `FB` | Fore Buchholz | `/C1`, `/C2`, `/M1`, `/M2`, each optionally with `/P` |
| `SB` | Sonneborn-Berger | `/C1`, `/C2`, each optionally with `/P` |
| `KS` | Koya System | `/L+n`, `/L-n` in half-point steps |
| `ARO` | average rating of opponents | `/C1`, `/C2`, `/M1`, `/M2` |
| `TPR` | Tournament Performance Rating | none |
| `PTP` | Perfect Tournament Performance | none |
| `APRO` | average TPR of opponents | none |
| `APPO` | average PTP of opponents | none |
| `RTNG` | rating | none |

Descriptors and variants are case-insensitive on input and canonicalized on
output. Lists are comma-separated, for example:

```text
DE/P,BH/C1/P,SB/C1,ARO/M1,TPR
```

Invalid acronyms, team score selectors, duplicate options and variants that do
not apply to a method fail closed. Tournament applicability is validated too:
BH, AOB and FB are forbidden in round robins by Article 8, while KS is accepted
only for round robin tournaments.

## Ranking APIs and TRF records

`FideTieBreakRanker::rank()` implements TRF record `202`: tournament points are
the primary key and the supplied descriptors are applied only inside tied
score groups. `rankByCriteria()` implements a complete ordered standings list,
including `PTS` wherever it occurs. `rankFromTournamentRecord()` selects the
correct behavior from canonical state metadata for TRF `202` or `212`.

The result preserves unresolved tied groups. `TieBreakRanking::standings()`
returns ready-to-serialize rows with competition ranks, player identity,
score and exact rational tie-break values.

## Exactness and special rules

All ranking values use integer/rational `Score`; binary floating point never
participates in comparisons. AOB is rounded half-up to two decimals. Rating
averages are rounded half-up to a whole number. TPR uses the official `p` to
`dp` table, and PTP searches the official rating-difference probability table
for the lowest whole rating satisfying the required expected score.

BH, FB, SB and AOB follow Articles 16.3-16.5 for Swiss unplayed rounds:
adjusted opponent scores, capped dummy scores, terminal requested-bye
adjustment and the VUR cut exception. Option `/P` treats scheduled forfeit wins
and losses as played. Fore Buchholz replaces every paired final-round result
with a draw for its calculation.

Rating-based tie-breaks fail closed if any player is unrated. The regulations
require those methods to be dropped unless tournament-specific unrated-player
handling was published; canonical state deliberately does not invent such a
policy.

Team, team-knockout and team score variants (`:MP`, `:GP`) are intentionally
reserved for phase 2.

## Normative references

- FIDE C.07, Play-Off and Tie-Break Regulations, effective 1 March 2026:
  <https://handbook.fide.com/chapter/TieBreakRegulations032026>
- FIDE B.02, Rating Regulations tables 8.1.1 and 8.1.2:
  <https://handbook.fide.com/chapter/B022024>
- `tools/upstream/fide/MandatoryTieBreaks-TRF26.pdf`
- `tools/upstream/fide/TRF-2026.pdf`
