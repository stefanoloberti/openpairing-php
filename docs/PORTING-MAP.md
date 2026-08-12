# Porting map

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
| BBP `src/swisssystems/dutch.cpp` | Dutch persistent round-graph coordinator and weight builder | Architecture mapped in ADR 0008; implementation pending, no source copied | Frozen 100x7 gate, then 30/50/80 and full corpus; local BBP oracle |
| BBP `src/swisssystems/burstein.cpp` | Non-normative comparison only | Studied; no source copied | Separate Burstein differential fixture |
| BBP `src/matching/**` | `Engine/Matching/BlossomMaximumWeight`, `DynamicUInt` | Semantic port at `8f9e3c5`; C++ pools/pointers replaced by PHP integer IDs and immutable 30-bit-limb arithmetic | 1,000 sparse sequential freeze/reweight graphs versus fresh Blossom and exhaustive oracle; odd-cycle and 80/300-vertex tests |
| BBP `dutch.cpp` preliminary whole-round pass | `Engine/Dutch/GlobalMatching` | Original PHP graph model following the studied lifecycle; not production-wired | 500/500 complete frozen rounds; 396/396 PAB score groups |

The matching files identified above are modified semantic derivatives of BBP
Pairings under Apache-2.0 and carry file-level provenance comments. No MIT
Gacrux source-level translation has been incorporated; Gacrux remains an
offline development oracle.
