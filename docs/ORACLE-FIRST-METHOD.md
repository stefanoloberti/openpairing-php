# Oracle-first development method

## Semantic baseline reset

On 2026-08-12 the replay entered semantic epoch
`fide-dutch-float/2-pab-downfloat`. The historical harness had encoded a PAB as
no float, so every earlier pairing checkpoint and QC distribution (including
74, 59, legacy 441 and shadow 425/427/444/466) asked both engines the wrong
question. Those numbers below are retained as experiment history only and are
not gate evidence. The epoch-2 frozen baseline is legacy 455/500 and shadow
489/500 with zero no-matchings; all 11 shadow residuals are Gacrux-inferior.

This is a fourth empirically disproved plausible conclusion: the motors did not
improve when legacy moved from 441 first to 453 and then 455; the reconstructed input became
semantically correct. Every future report must attest both source/corpus hashes
and `historicalFloatSemantics`.

The conditional-C9 episode adds a distinct methodological warning: an oracle
validates an output, not the mechanism that produced it. Applying C9 to every
PAB improved an incomplete coordinator and did not create a new Gacrux-inferior
case, yet the fixed BBP primary source proves C9 is conditional on the bye being
the single downfloater. The apparent improvement was compensation for the
missing matched/remainder/exchange lifecycle, not a valid rules interpretation.
Mechanism claims therefore require the normative text or pinned primary source
in addition to output parity.

OpenPairing treats a plausible explanation as a hypothesis, not a pairing
verdict. Before changing Dutch selection logic, the smallest isolating
counterfactual is sent to the independent Gacrux checker and the report records
the code and corpus identities. A first-difference criterion identifies where
quality first becomes measurably worse; it does **not** identify the component
that caused the earlier selection error.

This rule has prevented four reasonable but false conclusions:

1. Eleven historical deviations initially looked like legitimate equivalent
   variants. Full Gacrux classification proved all eleven inferior.
2. A HE/HO fix appeared to have no effect. Runtime source attestation proved
   Composer was loading a stale checkout, so the apparent regression result was
   invalid rather than the patch ineffective.
3. The 55 within-score-group PAB deviations appeared to be a conditional-C9
   defect. Counterfactual classification disproved that theory: first
   differences span QC12, QC9, QC14, HE2, QC17, QN8, QC16 and QC15.
4. Pairing replay treated a PAB as no historical float. Re-reading a minimized
   TRF against Gacrux proved it is a downfloat for the next round and invalidated
   every prior pairing baseline.

Consequences for implementation:

- Port and compare the complete QC6-QC21, HE1-HE2 and HO1-HO5 vector, rather
  than patching the first differing criterion.
- Keep selection in the persistent global coordinator. QC12 dominance is not
  evidence of a defect in `ColourAllocator`; changing the PAB can cause colour
  quality to be the first later observable loss.
- `same-deviation` is never self-certifying. It and `divergent-deviation` must
  be passed to Gacrux, because shared code or interpretation can make both PHP
  paths wrong in the same way.
- Preserve negative tests for code/corpus identity and retain minimized
  counterexamples as regressions.

The shadow gate remains 500/500 exact with zero candidate regressions and no
unclassified oracle-required buckets. Larger 30/50/80 runs additionally record
per-bracket matching time so repeated full rematches can be measured before the
122,008-tournament replay.

The first complete-vector static-rematch experiment is a negative architectural
control: on the frozen 500 rounds it produced 119 `both-exact`, 7 progress, 321
regressions, 5 same deviations, 47 divergent deviations and one no-matching.
Gacrux classified all 52 oracle-required deviations as inferior. This confirms
that the vector alone is insufficient: progressive persistent freeze state,
MDP selection and remainder exchange are part of the algorithm.

A second negative control froze every heterogeneous edge selected by the HE
matching before HO. It produced 60 no-matchings and only 370 exact results.
That shortcut was removed: BBP first fixes which MDPs are matched and then
freezes their opponents one at a time with a fresh completion check.

Filtering the graph so each selected MDP retained only one trial opponent was
also rejected: it caused 107 no-matchings. BBP finalizes choices by mutating
weights in the persistent graph; deleting alternative edges is not equivalent.

The first traced progressive checkpoint (after the Blossom maximum-cardinality
and stale-best-edge fixes) reaches 427/500 exact: 395 both exact, 32 candidate
progress, 46 candidate regressions, 9 same deviations, 18 divergent deviations
and zero no-matchings. Its code SHA-256 is
`a18d8a0e642cd1e763fb88cec770f588ce68bfb6d593640b72bcbb934a78a7e6` and
its corpus manifest is
`e05981129109020ff5b35a77d972b3c36887823399dc2f5b7fd0e80d8b8bad44`.

The tempting single-cause hypothesis “all regressions are an early freeze whose
damage appears in a later bracket” is also false. Gacrux finds 25/46 candidates
with a worse full quality vector: 23 first differ in the same bracket as the
first non-reference freeze and only 2 in a later bracket. The other 21 have the
same complete Gacrux quality vector but a non-exact deterministic result, so no
quality `firstDifference` exists. Remainder exchange remains necessary, but is
not a universal explanation for the regressions.

The persistent matching computer is correctness-first: it stores one graph,
supports topology-preserving reweight and irreversible freeze, but deliberately
recomputes Blossom from scratch after each mutation. One thousand random sparse
freeze/reweight sequences must match a fresh Blossom exactly and an independent
exhaustive solver in status and optimum weight. A warm-start implementation is
only a future optimization and must pass this same test unchanged.

The original static 1,000-graph Blossom differential had passed while still
containing the maximum-cardinality delta1 termination bug and stale `bestEdge`
cache bug. That gate was insufficient. Both defects were exposed only by the
strengthened sparse-topology and sequential freeze/reweight tests. Confidence
in the matcher rests on this strengthened gate, not the original static run.

A direct but incomplete port of BBP's sequential MDP-opponent selection is a
negative control. Freezing each selected MDP pair without simultaneously
carrying BBP's full `matched`, remainder and exchange state produced 170
no-matchings and only 279 exact shadow rounds (265 both exact, 14 progress).
It was removed in full. Sequential freeze is not independently composable with
the retained bracket coordinator; the remainder lifecycle must be ported as one
coherent state machine.

A smaller HE-model defect was independently fixable: HE vector width counted
every player in higher score groups, including players already frozen and no
longer present in the bracket BSN map. Counting only current-BSN MDPs raises the
shadow checkpoint from 427 to 444/500: 413 both exact, 31 progress, 28
regressions, 19 same deviations, 9 divergent deviations and zero no-matchings.
The HE2 residual cluster disappears completely. Gacrux classifies 7/28
regressions as inferior (QC9 4, QC12/QC14/QC18 one each); 21 have the same full
quality vector but a non-exact deterministic choice. All 28 shared/divergent
legacy deviations remain Gacrux-inferior, led by QC14 23. Although 444 exceeds
the legacy's 441, the 500/500 zero-regression switchover gate is unchanged.

Applying QC9 to every PAB candidate, instead of the disproved
single-downfloater heuristic, is retained after oracle classification: shadow
reaches 445/500 and inferior candidate regressions fall from 7 to 4. Two QC9
cases remain, proving that applicability alone does not solve PAB selection.

The apparent 21 equal-full-quality regressions were not alternative matchings:
all were round-one results with exact opponents and bye but boardwise colours
allocated through the general per-pair path. Reusing the public initial-round
slice whenever the shadow opponent signature is identical removes all 21 and
raises the checkpoint to 466/500: 437 both exact, 29 progress, 4 regressions,
23 same deviations, 7 divergent deviations and zero no-matchings. Gacrux marks
the four regressions inferior (QC9 2, QC12 1, QC18 1) and all 30
shared/divergent deviations inferior (QC14 24, QC16 2, QC12 2, QN8 1, QC15 1).
No switchover is allowed before those 34 deviations reach zero.
