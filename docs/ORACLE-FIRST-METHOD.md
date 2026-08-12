# Oracle-first development method

## Current status (2026-08-13)

The epoch-2 frozen Dutch gate is closed at 500/500 exact under tag
`epoch-2-dutch-500`. One 80-player tournament has completed 15/15 exact, but
medium-field classification and the full 122,008-tournament replay remain
open. The current blocker is performance: the limbs baseline takes 344.667
seconds for that tournament. The active optimization protocol changes one
factor per checkpoint and requires limb/GMP same-seed parity, the frozen gate,
and the 80-player differential before retention.

Above the bounded enumerator's practical range, internal five-way shadow
buckets are not the acceptance language. Large-field verification is candidate
versus the recorded reference: exact, colour variant, deviation or no matching.
Every non-exact completed candidate is classified by Gacrux. Legacy evidence is
used only for rounds where the legacy engine actually terminates.

The first full corpus partition is now closed: all 49,961 rounds from 10,000
seven-player tournaments are exact at commit `4a93b7a`. Before that run, the
same partition produced 37 Gacrux-inferior deviations and six no-solutions.
The exhaustive matcher reproduced the defects, while BBP agreed with the
reference on all 37 completed residuals. This isolated two shared-model bugs:
the PAB score-position complement omitted QC18/QC20, and conditional C9 was
incorrectly encoded as a prefix that dominated QC6-QC8. The corrected field
ordering eliminated all 43 affected tournaments without heuristic adjustment.

The next 9-player partition closes at 69,922/69,922 exact rounds. Its only
post-C9 residual illustrates why a checker's named first difference is not a
root-cause label: Gacrux reported QC20 after bracket reconstruction, while a
direct comparison of PHP matching vectors showed the first PHP error at QC11.
The missing absolute-preference colour-sequence branch was ported from the
source formula; both Gacrux and BBP then agreed with the corrected PHP output,
and the complete p7 partition remained exact.

## Semantic baseline reset (historical epoch narrative)

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

A diagnostic must also prove that it ran. Oracle/classifier reports record the
number of evaluated cases and fail when that count is zero unless the operator
passes an explicit `--allow-empty`. A silent empty selection is infrastructure,
not evidence; this is the same failure class as stale autoload and an oracle
subprocess that returns no requested round.

Repeated failure of different encodings for the same concept raises the level
of suspicion from arithmetic to semantics. The matched-MDP incident-edge bug
was not repairable by another penalty formula because an MDP whose opponent is
outside the current score level is not selected in that bracket. Primary-source
definitions are rechecked before a further weight experiment.

The MDP incident bit is currently 0/1, never -1/0/+1. Canonical Dutch QC and HE
fields may legitimately be signed; `LexicographicWeightEncoder` translates
each observed field by its minimum before unsigned packing. A dedicated unit
test proves that this translation preserves signed ordering without a borrow
into a more significant criterion.

The later lifecycle port supplied the missing state and made conditional C9 a
real improvement rather than compensation: the frozen replay moved from
490/500 to 494/500 with zero no-matchings. Conversely, a partial MDP-identity
selection cycle fixed T0032 in a three-file slice but caused 12 no-matchings in
the complete sample. It was reverted. Micro-corpus progress is therefore a
diagnostic, never a checkpoint; every retained mechanism must survive the full
frozen gate.

The final convergence adds a fifth lesson. A Gacrux `HE2` first difference in
T0056 was caused by an earlier missing QC14 endpoint contribution, not by HE
ordering. Applying the primary-source rule to every current-bracket edge moved
496/500 to 499/500. The last shared deviation then exposed the same
representation issue for the sole unmatched PAB: because it has no incident
edge, QC14/QC16 must be carried by the algebraic complement on matched eligible
endpoints. BBP independently produced the corpus pairing. The canonical fix
closed the frozen gate at 500/500 without regressions.

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

A later, narrower attempt confirmed the same boundary after the PAB was
correctly represented as the unmatched real vertex. Letting HE fix only the MDP
identities while HO selected their opponents, but still omitting coupled
`matched` and remainder state, produced 199 no-matchings. Commit `b733651` was
reverted by `958c8ce`; the machine-readable negative control is
`docs/evidence/negative-he-selection-without-remainder.json`. MDP selection,
opponent finalization and remainder construction must land as one lifecycle.

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
