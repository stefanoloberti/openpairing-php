# Porting map

## Current status (2026-08-13)

The persistent Dutch lifecycle is the public `DutchPairingEngine`. It has
closed the frozen 7/30/50/80-player gates. The bounded implementation remains
`LegacyDutchPairingEngine` as a small-field oracle. The complete p7, p9, p15
and p30 corpus partitions are closed at 69,998 tournaments and 609,874 exact
historical rounds; p50, p80 and accelerated pairing partitions remain open.

| Source studied | PHP target | Treatment | Verification |
|---|---|---|---|
| FIDE Dutch 2026 arts. 1-5 | `Engine/Dutch` | Original bounded implementation | Unit, integration, Gacrux and BBP differential corpus |
| FIDE Burstein effective 2026-02-01 | `Engine/Burstein` | Original C1-C8, BSN and colour implementation | Normative unit/integration cases and BBP development comparison |
| FIDE tie-break regulations effective 2026-03-01 | `Domain/TieBreak` | Original individual formulas and Article 16 handling | Exact rational normative fixtures |
| FIDE accelerated systems effective 2026-02-01 | `Acceleration/BakuAcceleration`, `Player::pairingScore()` | Original schedule/state transformation and engine integration | Schedule, late-entry and two-engine integration tests |
| Gacrux `pairing.py`, `pairingdutch.py` | Dutch observable behaviour | Studied; no source copied | Local isolated oracle |
| Gacrux `crosstabledutch.py` | `DutchQualityVector`, `DutchQualityModel` | Semantic MIT port of complete QC/HE/HO edge-quality ordering; PHP modelling original | Unit, shadow and Gacrux historical classification |
| Gacrux `pairingchecker.py` | Oracle bridge | Adapter only | Process/status tests and corpus |
| Gacrux `trf2json.py` + official TRF positions | `Trf2026Codec` | Original fixed-column codec | Round-trip and malformed-input tests |
| BBP `src/swisssystems/dutch.cpp` | Dutch persistent round-graph coordinator and weight builder | Lifecycle implemented in original PHP following the mapped architecture; public Dutch path, no source copied | Frozen 7/30/50/80-player gates exact; p7/p9/p15/p30 full partitions exact; p50/p80/accelerated partitions open |
| BBP `src/swisssystems/burstein.cpp` | Non-normative comparison only | Studied; no source copied | Separate Burstein differential fixture |
| BBP `src/matching/**` | `Engine/Matching/BlossomMaximumWeight`, `DynamicUInt` | Semantic port at `8f9e3c5`; C++ pools/pointers replaced by PHP integer IDs and immutable 30-bit-limb arithmetic | 1,000 sparse sequential freeze/reweight graphs versus fresh Blossom and exhaustive oracle; odd-cycle and 80/300-vertex tests |
| BBP `dutch.cpp` preliminary whole-round pass | `Engine/Dutch/GlobalMatching` preliminary phase | Original PHP graph model; retained as an earlier phase inside the implemented shadow lifecycle | Historical preliminary audit: 500/500 completable and 396/396 PAB score groups; final lifecycle gate: 500/500 exact |

The matching files identified above are modified semantic derivatives of BBP
Pairings under Apache-2.0 and carry file-level provenance comments. No MIT
Gacrux source-level translation has been incorporated; Gacrux remains an
offline development oracle.

## Dutch weight-field map

`LexicographicWeightEncoder` accepts signed PHP components, derives each
dimension's observed minimum and maximum, and packs `component - minimum` into
an unsigned `DynamicUInt` field sized for the maximum matching sum. Equal
cardinality gives every candidate the same offset sum, so signed QC values
cannot borrow from a preceding field. Cardinality is encoded above all quality
fields and the deterministic mate vector below all of them.

| BBP concept | PHP component | Lexicographic position | Input domain |
|---|---|---|---|
| PAB eligibility/completion | `pabSelectionWeightedEdges::$selection` | Prefix before QC6 | non-negative endpoint complement |
| Conditional C9 PAB rank | `pabSelectionWeightedEdges` at the canonical QC9 offset | After QC8, before QC10 | non-negative unplayed-game complement |
| C6-C21 | `DutchQualityVector` QC6-QC21 slice | Canonical order in `DutchQualityVector::ORDER` | signed integer penalties/rewards |
| Finalized matched MDP identities | `constraintWeightedEdges::$violations` | After QC21, before HE1 | bit, exactly 0 or 1 per MDP |
| Selected MDP opponent order | `constraintWeightedEdges::$opponentOrder` | After matched-MDP bits, before HE1 | dense rank 0 through current BSN width |
| HE1-HE2 and HO1-HO5 | tail of `DutchQualityVector` | After MDP lifecycle controls | signed integer/vector criteria |
| Deterministic matching choice | encoder mate vector | Below every semantic field | unsigned partner digits |

There are two deliberately separate notions around BSN. The bracket sequence
number is normative identity used by HE/HO and remains stable while selected
MDP opponents are frozen. Once those opponents are final, `hoBsn` is a newly
defined dense sequence of the actual remainder residents. Vector array offsets
are allocation indices derived from those BSNs; they never renumber the
normative bracket sequence in place. Commit `326212f` removed the earlier
destructive resequencing that violated this distinction.
