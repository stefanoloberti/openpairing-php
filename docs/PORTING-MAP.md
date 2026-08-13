# Porting map

## Current status (2026-08-13)

The persistent Dutch lifecycle is the public `DutchPairingEngine`. It has
closed the frozen 7/30/50/80-player gates. The bounded implementation remains
`LegacyDutchPairingEngine` as a small-field oracle. The complete p7, p9, p15
and p30 corpus partitions are closed at 69,998 tournaments and 609,874 exact
historical rounds; p50, p80 and accelerated pairing partitions remain open.
The reconciled 122,008 denominator also includes p150 and ten historical misc
tournaments; both are installed in the corrected persistent queue before the
separate acceleration and over-256-player capacity gates.

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

## Dutch remainder/exchange map

This table maps the structural exchange section of BBP `dutch.cpp`; it is not
an assertion that output agreement alone proves line-for-line mechanism
parity. The p50 HE2/HO1/HO5 cluster initially attributed to this section was
instead caused upstream by participation-indexed float history. The isolated
round-indexed fix closed all 14 cases without the experimental exchange patch.
The map is retained because larger strata may still exercise distinctions not
covered by the current gates.

| BBP loop/concept | Inputs and value domain | Required effect | PHP point/status |
|---|---|---|---|
| `edgeWeightComputer` (around lines 1070-1110) | Base edge weight; dense remainder index `0..r-1`; `remainderPairs` `0..floor(r/2)` | Append unsigned fields that first minimize exchanged-player count, then exchanged BSN difference; never alter higher Dutch QC fields | `DutchQualityModel`/lexicographic encoder provide the semantic weight base; a dedicated exchange component remains the intended home for exact structural parity |
| Stable HE matching and `matched[]` (1110-1275) | Whole persistent matching; MDP membership bit per player | Select which MDPs are matched in this bracket, then freeze their opponents one at a time while retaining suffix completability | `selectMdpIds()` plus `HO-mdp` rematch/freeze loop; implemented and covered by freeze-event and small-field gates |
| Build `remainder` and `remainderPairs` (1282-1314) | Current-score residents left after finalized heterogeneous pairs; original/stable BSN identity | Preserve ordered remainder; derive the S1/S2 boundary from the updated stable matching, never from a stale pre-freeze count | Coordinator builds `hoBsn` from surviving residents and rematches; the rejected stale-count experiment and its 0/14 result are versioned evidence |
| Reweight all remainder edges (1316-1341) | Every valid edge within the ordered remainder | Add exchange-count and BSN-difference ordering below the already-fixed higher criteria | Current HO rematch carries complete Dutch vectors; explicit BBP exchange-field parity is mapped, not claimed by the 14-case correction |
| Compute `exchangeCount` (1343-1355) | Stable matching relative to the S1/S2 boundary | Count first-group players not paired across the boundary; range `0..remainderPairs` | Must be computed from the current post-freeze optimum in the future dedicated component |
| Select lower S1 players (1357-1424) | Reverse S1 order; remaining exchange count | Test/finalize the lowest eligible S1 identities without losing a complete optimal suffix | No separate PHP component yet; current global rematch/freeze supplies completability but not claimed loop parity |
| Select higher S2 players (1427-1512) | Forward S2 order; remaining exchange count greater than one | Test/finalize the highest eligible S2 identities with BBP restoration rules for temporary weights | No separate PHP component yet; future port must expose temporary versus frozen constraints explicitly |
| Finalize exchanged identities (1514-1546) | Stable matching plus selected exchange membership | Permanently remove incompatible intra-group alternatives and clear temporary selection bits | Persistent graph supports edge freezing/reweighting; exact exchange-membership operation remains to be isolated and differentially tested |
| Choose opponents and freeze (1549 onward) | Final S1 membership; reverse opponent order; valid base edges | Select opponents deterministically, freeze each pair, then validate the remaining graph before advancing | `HO-remainder` sequential rematch/freeze is implemented; a future exchange component would feed its ordered membership/constraints |
| Validity lookahead (around 1606) | Residual validity graph after each candidate finalization | Reject any selection that prevents completion of the suffix | `PersistentWeightedMatchingComputer` plus rematch status; sequential freeze/reweight properties are checked against fresh Blossom and the exhaustive oracle |

The planned component contract is therefore: ordered remainder, stable
matching, immutable normative BSNs and valid-edge set in; finalized S1/S2
membership plus opponent-order constraints out. It must not own float-history
classification, score-group construction or the Dutch QC vector: those are
upstream inputs, and conflating them is precisely what made the old p50
diagnosis misleading.
