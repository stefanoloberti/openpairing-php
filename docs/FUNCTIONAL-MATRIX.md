# Functional reconnaissance

| Capability | Gacrux | BBP | OpenPairing current state |
|---|---|---|---|
| FIDE Dutch 2026 | Yes | Yes | Public persistent whole-round C1-C21 engine; frozen 7/30/50/80-player gates exact, full historical-corpus gate open |
| FIDE Burstein 2026 | No production engine found | Present; upstream warns of imperfections | Separate bounded C1-C8/BSN engine; official FIDE rules are normative |
| Weighted general matching | NetworkX development dependency | Native general matcher | Polynomial arbitrary-width Blossom in production; 22-vertex exhaustive matcher retained as a test oracle |
| Colour allocation | Yes | Yes | Dutch and Burstein 5.2.1-5.2.5 variants |
| PAB/bye | Yes | Yes | Eligibility, unmatched-vertex PAB selection and persistent global completion |
| Scoregroups/MDP/floats | Yes | Yes | Dutch C6-C21; Burstein C5-C7 |
| TRF-2026 read/write | Yes | Yes; parser gaps documented | Fail-closed individual subset, Dutch + Burstein + Round Robin standings |
| JSON | ChessJSON | No canonical API | Project-owned canonical JSON v1 |
| Pairing checker | Yes | Yes | Independent history/C1-C3/colour/PAB/accounting checks |
| Individual tie-breaks | Extensive | Burstein opposition values | Complete FIDE 2026 mandatory individual set, extra Handbook keys and TRF 202/212 standings |
| Baku acceleration | Input support | Input support | Canonical schedule generator and virtual scores; TRF 250/XXA import is roadmap v1.1 |

Exact oracle output is evidence, not the definition of validity. The PHP
engines return no partial pairing on no-solution or resource exhaustion.

BBP currently requires `0000` in an unplayed `001` block and rejects the
official `162 X` field. The development adapter performs those two translations
only in an isolated temporary input; canonical and TRF runtime data are not
changed.
