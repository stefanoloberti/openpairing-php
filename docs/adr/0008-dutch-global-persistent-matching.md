# ADR 0008: Dutch integration uses one persistent round graph

Status: accepted and small-field verified; larger-field performance pending.

Baseline epoch: `fide-dutch-float/2-pab-downfloat`. Every replay figure below
that predates this marker (74, 59, legacy 441 and shadow 425/427/444/466 plus
their QC distributions) is retained only as non-gating debugging history. The
correct frozen baseline is legacy 455/500 and shadow 489/500 with zero
no-matchings. All 11 shadow residuals are independently Gacrux-inferior.

The completed lifecycle reaches 500/500 exact with zero no-matchings and zero
regressions, attested by `docs/evidence/pairing-epoch2-dutch-500.json` and Git
tag `epoch-2-dutch-500`. The final two general defects were missing QC14/QC16
endpoint contributions on heterogeneous edges and on the genuinely unmatched
PAB endpoint. Production wiring remains forbidden until the 80-player
performance gate is practical and the subsequent full-corpus replay closes.

The polynomial matcher must not be inserted independently into the current
`solveGroups()` loop. Independent bracket optima cannot compare PAB choices or
float chains and therefore cannot close the frozen epoch-2 gate.

The inspected BBP Pairings implementation at commit
`8f9e3c5ffdc4d7a08a33d31c6fb49b4e7acef4d5`,
`src/swisssystems/dutch.cpp`, is more precise than either “one local matcher per
bracket” or “one immutable whole-round matching”:

1. It creates one `matchingComputer` whose vertices are every active player.
2. It installs whole-round compatibility/completability weights and computes a
   preliminary matching. With an odd field, a self-match represents the PAB;
   this pass determines its score group and whether C9 applies.
3. It reweights the same persistent graph while advancing through score groups.
   The current bracket includes inherited moved-down players, its residents and
   the next score group needed to prove completability.
4. It recomputes matching while selecting MDP opponents, remainder exchanges
   and deterministic BSN order, then freezes accepted edges. Unmatched vertices
   remain in the same graph and flow to the next bracket.
5. Only after all brackets have been finalized is the round emitted.

An attempted partial port of BBP's per-score-group MDP identity selection
improved one HE2 residual in isolation but produced 12 no-matchings and reduced
the full frozen replay to 482 exact rounds. It was reverted immediately. The
failure shows that `remainingMatchedMovedDownScoreGroupPlayers` and its
matched/remainder accounting must be ported atomically; fixing earlier MDPs as
unmatched without that state is over-constraining.

Thus bracket structure remains control flow, but never becomes a sequence of
isolated matching problems. Global PAB/float information is encoded in the
persistent graph and retained between bracket decisions.

The freeze invariant is: **no pair is frozen when another eligible edge in the
same bracket has a lexicographically better complete-round continuation**.
`DutchFreezeLookahead` makes this executable by removing each candidate's two
vertices, solving the entire residual graph and comparing candidate plus suffix
weight. Its regression has two locally equal choices in bracket N whose only
difference appears in bracket N+1; the globally better continuation must win.
An additional regression rejects a locally stronger edge that makes the suffix
incomplete. This component remains outside production until the Dutch-specific
weight fields and persistent coordinator are complete.

The high-to-low basic edge fields in `computeEdgeWeight()` are:

- compatibility, completion and PAB eligibility;
- maximum pairs and paired scores in the current bracket;
- maximum pairs and paired scores in the next bracket;
- C9 unplayed-game rank when the single downfloater receives the PAB;
- colour preference fields;
- repeated downfloat/upfloat counts from one and two rounds before;
- score-position fields for those repeated floats;
- reserved HE/HO and exchange-order fields.

The PHP integration will therefore introduce a round-graph coordinator and a
Dutch weight builder above `BlossomWeightedGeneralMatcher`. It will not modify
the verified Blossom core and will not replace `solveGroups()` in place. The
bounded enumerator remains a small-field diagnostic oracle until the new path
passes the frozen gates.

Acceptance proceeds without changing the corpus. Criterion 1 is formally
satisfied by tag `epoch-2-dutch-500`; criteria 2 and 3 remain open:

1. **Satisfied.** 500/500 exact on the frozen 100x7 sample, with no regression of the 455
   epoch-2 legacy-exact rounds and no unclassified shadow bucket.
2. **Open.** No resource-limit results in the existing 30/50/80-player samples, with all
   deviations classified rather than treated as variants.
3. **Open.** Full replay of all 122,008 previously decodable non-accelerated tournaments.

Runtime-identity attestation from the historical verifier is part of every gate.

Before switchover, the complete global engine runs only in shadow beside the
bounded engine. `ShadowPairingComparison` defines the report categories:
`both-exact`, `candidate-progress`, `candidate-regression`, `same-deviation`
and `divergent-deviation`. Any `candidate-regression` blocks release; every
divergent deviation requires Gacrux classification. Production switches only at
500/500 with zero regression, after which the bounded engine remains a small
field test oracle.

`same-deviation` also requires Gacrux classification: agreement between two PHP
paths can merely expose a shared interpretation or shared-code defect. The
historical verifier exports both oracle-required buckets in `shadowSamples` and
the classifier accepts `--sample-set=shadow-oracle`.

The first implemented slice is the read-only preliminary pass in
`Engine/Dutch/GlobalMatching`: one compatibility graph over all active players,
Blossom completability, PAB eligibility and lowest completable PAB score group.
It is not called by the production engine. Its frozen audit is 500/500 complete
and 396/396 correct PAB score groups (341 exact assignees, 55 awaiting legal C9
and bracket refinement).

The isolated Gacrux counterfactual audit of those 55 disproves a C9-only
explanation. First differences are QC12 32, QC9 16, QC14 2, and HE2/QC17/QN8/
QC16/QC15 one each. Therefore the coordinator must retain the complete quality
cascade while selecting the PAB assignee; C9 cannot be added as an isolated
shortcut.

The quality cascade now has one canonical PHP representation:
`DutchQualityVector` fixes QC6, QC7, QN8, QC8, QC9, QC10-QC21, HE1-HE2 and
HO1-HO5 ordering, including expanded vector components. `DutchQualityModel`
produces edge penalties from that representation and
`DutchQualityRoundGraphBuilder` turns them into maximizing Blossom weights.
This is a semantic port of the criterion updates in Gacrux
`crosstabledutch.py` at `14a34a2c`; it remains shadow-only while bracket freeze
state is completed.

`DutchShadowGlobalCoordinator` records one preliminary timer plus one
`quality-rematch` timer per occupied score level. This deliberately measures
the current full-rematch cost. Incremental matching is a deferred optimization,
not an acceptance requirement for n=7.

The initial static-rematch shadow is retained as a negative control, not a
candidate: 119 both exact, 7 progress, 321 regressions, 5 same deviations, 47
divergent deviations and one no-matching on the frozen 500 rounds. Gacrux marks
all 52 oracle-required cases inferior (first differences QC6 23, QC14 7, HO1
7, QC12 6, QN8 3, QC7 3, HE2 2, QC16 1). The missing element is progressive
freeze/MDP/remainder state, not another isolated criterion patch.

Freezing every HE edge in one operation is also rejected by experiment: it
caused 60 no-matchings and reduced exact shadow results to 370. The accepted
next design must reproduce BBP's two-stage choice (select matched MDPs, then
freeze each MDP opponent after a recomputation), followed by remainder exchange.

An edge-filtering approximation of the second stage caused 107 no-matchings and
was removed. The persistent implementation must freeze through weight updates
while retaining alternative edges for global completion, as BBP does.

The traced shadow checkpoint is 427/500 exact with zero no-matchings: 395 both
exact, 32 progress, 46 regressions, 9 same deviations and 18 divergent
deviations. A dedicated Gacrux cross-audit rejects an early-freeze-only
explanation: among 25 candidates with inferior full quality, 23 first differ in
the same bracket as the first non-reference freeze and only two later. The
remaining 21 are equal in full Gacrux quality but non-exact. It is laboratory
code only and does not alter the 441/500 production path.

`PersistentWeightedMatchingComputer` now supplies the mutation contract needed
by the coordinator. Its initial policy recomputes the optimum from scratch after
every freeze/reweight; it does not claim warm-start incrementality. The original
1,000 static/complete-graph differential passed despite two latent defects and
was therefore insufficient. The strengthened gate uses 1,000 random sparse
freeze/reweight sequences against both fresh Blossom and the exhaustive matcher;
it exposed and fixed delta1 prematurely terminating maximum-cardinality stages
and cached best edges surviving blossom topology changes. Confidence rests on
this sequential sparse gate. The bounded production enumerator remains the
permanent small-n CI oracle even after a future switchover.

The shadow coordinator now instantiates that computer once and preserves the
induced active-graph topology across HE/HO reweights; former edge filters are
lexicographically dominant weight fields, and accepted pairs are removed only
through `freeze()`. The checkpoint remains exactly 427/500, which isolates the
mechanical integration from Dutch rule progress. A topology regression test
records every matcher call and requires HE/HO on the same active vertices to
have identical edge keys.

Sequential MDP-opponent freeze cannot be landed in isolation. An experimental
partial port, lacking BBP's coupled remainder/exchange state, caused 170
no-matchings and fell to 279/500 exact. It was removed. The next Dutch change
must port the `matched` plus remainder/exchange lifecycle coherently rather than
adding local completion exceptions.

The first retained rule improvement after mechanical integration fixes HE
width to count only MDPs still represented in the current bracket BSN map. It
produces 444/500 exact with zero no-matchings and removes HE2 from every
inferior regression. This is the first shadow checkpoint above legacy
(441/500), but 28 candidate regressions still categorically block switchover.

Two further retained corrections raise shadow to 466/500. QC9 is applied to
every PAB edge, reducing but not eliminating QC9 regressions. Initial-round
colour orientation is delegated to `InitialDutchPairingEngine` when shadow has
the same opponent/bye signature; this removes 21 colour-only false regressions
without perturbing matching weights. The remaining four regressions and 30
shared/divergent deviations are all Gacrux-inferior, so the gate remains open.
