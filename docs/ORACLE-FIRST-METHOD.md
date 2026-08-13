# Oracle-first development method

## Compact operating rules

1. A closed gate certifies only the distribution it exercised; every new scale
   or tournament shape is a fresh semantic probe. See [current campaign
   lessons](#current-status-2026-08-13).
2. An oracle validates outputs, not mechanisms; a mechanism claim also needs
   the normative text or a pinned primary source. See [semantic baseline
   history](#semantic-baseline-reset-historical-epoch-narrative).
3. A phase present upstream but absent locally is a structural difference, not
   proof that it caused the observed deviation. See [the p50 float-age
   counterexample](#current-status-2026-08-13).
4. A checker's first differing criterion identifies where quality first becomes
   measurable, not necessarily the decision that caused the error. See [the p9
   QC20/QC11 case](#current-status-2026-08-13).
5. Every diagnostic and oracle must report a non-zero evaluated-case count and
   fail closed on an empty selection. See [historical diagnostic
   controls](#semantic-baseline-reset-historical-epoch-narrative).
6. Evidence must attest the source, harness, corpus, semantic epoch and runtime
   backend; mixed identities must never be aggregated. See [workspace
   isolation](#physical-workspace-isolation).
7. Official runs use a clean, committed, physically independent checkout whose
   isolation is negatively tested before computation begins. See [workspace
   isolation](#physical-workspace-isolation).
8. The denominator is an input to the oracle: partitions and exclusions must
   reconcile exactly before a corpus claim can close. See [the campaign
   reconciliation](#current-status-2026-08-13).
9. Change one semantic or performance factor per checkpoint, measure it, and
   retain negative experiments as evidence rather than compensating patches.
   See [semantic baseline history](#semantic-baseline-reset-historical-epoch-narrative).
10. Above the legacy engine's practical range, compare directly with the
    recorded reference and classify every non-exact result; absence of a legacy
    comparison is never success. See [large-field verification](#current-status-2026-08-13).
11. State semantics reconstructed by a historical harness must also be proven
    through the live public API with a discriminating regression. See [the
    round-indexed float test](#current-status-2026-08-13).
12. Fail closed at every boundary: no silent infrastructure errors, convenient
    "equivalent" bucket, partial aggregate, automatic retry after a semantic
    failure, or claim ahead of its evidence. See [workspace
    isolation](#physical-workspace-isolation).

## Current status (2026-08-13)

A closed gate certifies semantics only on the distribution it exercised. The
500/500 seven-player gate remains valid, but it established only that the
retained lifecycle was sufficient for those small fields. The first 20 p50
shards exercised deeper brackets and exposed 14 Gacrux-inferior pairings whose
first differences are HE2 (9), HO1 (3) and HO5 (2). Scale is therefore a
semantic probe, not merely a performance variable. Every new field-size stratum
must be allowed to falsify lifecycle completeness independently; success at a
smaller size may guard against regressions but never substitutes for that gate.

The p50 campaign was paused as soon as the early classifier proved all 14
candidate deviations inferior. A narrow experiment that recomputed the number
of remainder pairs from the post-MDP-freeze matching changed 0/14 results and
was rejected. A subsequent source-level reduction found that all 14 shared a
different cause: players absent in rounds 29 and 30 retained a round-28 float,
and the PHP quality models treated the last stored participation as UF1/DF1 at
round 31. Gacrux indexes float age by round number, so that history had expired.
The isolated round-indexed correction made all 20 exercised round tuples exact
with zero no-solutions; the full S1/S2 experiment was not used to obtain that
result. The three attested round-filter reports are
`docs/evidence/p50-float-age-round-29.json` through
`docs/evidence/p50-float-age-round-31.json`. This is another oracle-first warning: a criterion label and a plausible
missing phase do not establish causality. The machine-readable negative control
for the rejected counter-only experiment remains
`docs/evidence/negative-p50-remainder-pairs-recompute-20260813.json`.

There is a distinct companion rule to “the oracle validates outputs, not
mechanisms”: finding a phase in primary source that the implementation lacks
does not prove that the missing phase caused the observed deviations. Source
comparison establishes a structural difference; causality still requires a
discriminating counterexample. In p50 the BBP exchange loops were genuinely
absent, yet the 14 observed failures disappeared solely when upstream float
state was corrected. The live public-engine regression
`DutchPairingEngineTest::testPublicEngineExpiresFloatsAcrossTwoSkippedTournamentRounds`
uses one history at two different round placements and proves that the
production API—not only the replay harness—selects the round-indexed outcome.

The epoch-2 frozen Dutch gate is closed at 500/500 exact under tag
`epoch-2-dutch-500`. Direct-reference public-path samples at 30, 50 and 80
players are closed at 110/110, 150/150 and 150/150 exact rounds. Complete
medium/large corpus partitions and the full 122,008-tournament replay remain
open. The historical limbs baseline took 344.667 seconds for one tournament;
the retained GMP/JIT/residual-cache path later completed ten 80-player
tournaments (150/150 exact rounds) in 743.355 seconds total. Full-corpus
throughput remains the current blocker. The optimization protocol changes one
factor per checkpoint and requires limb/GMP same-seed parity, the frozen gate,
and the 80-player differential before retention.

Above the bounded enumerator's practical range, internal five-way shadow
buckets are not the acceptance language. Large-field verification is candidate
versus the recorded reference: exact, colour variant, deviation or no matching.
Every non-exact completed candidate is classified by Gacrux. Legacy evidence is
used only for rounds where the legacy engine actually terminates.

This distinction was introduced after the first 30/50-player shadow runs left
23 and 78 rounds without a legacy comparison and retained one unclassified
50-player `same-deviation`. “Zero shadow no-matching” was therefore not a
correctness claim. The replacement public-path runs compare every round
directly with the recorded reference; only those direct-reference reports may
close a large-field sample.

The first four corpus field-size partitions are now closed: p7, p9, p15 and
p30 total 69,998 tournaments and 609,874 exact historical rounds, with zero
colour variants, deviations, no-solutions and resource limits. The p30
aggregate covers 220,000/220,000 rounds in 256 immutable shards at commit
`c48fd05`; its zero-case Gacrux post-gate and all runtime, harness and corpus
identities are attested. The p7 partition first closed all 49,961 rounds from
10,000 seven-player tournaments at commit `4a93b7a`. Before that run, the
same partition produced 37 Gacrux-inferior deviations and six no-solutions.
The exhaustive matcher reproduced the defects, while BBP agreed with the
reference on all 37 completed residuals. This isolated two shared-model bugs:
the PAB score-position complement omitted QC18/QC20, and conditional C9 was
incorrectly encoded as a prefix that dominated QC6-QC8. The corrected field
ordering eliminated all 43 affected tournaments without heuristic adjustment.

A campaign denominator is also an oracle input. The first persistent queue
summed to 120,998, not the documented 122,008: it omitted 2,000 p150 and ten
historical `misc` tournaments while adding the separate 1,000 accelerated
files. The arithmetic check caught the mismatch before a green queue could be
misreported as a complete corpus gate. Every final campaign now declares both
its per-partition counts and their exact reconciled sum; adjacent capacity
sets never substitute for a missing historical partition.

The next 9-player partition closes at 69,922/69,922 exact rounds. Its only
post-C9 residual illustrates why a checker's named first difference is not a
root-cause label: Gacrux reported QC20 after bracket reconstruction, while a
direct comparison of PHP matching vectors showed the first PHP error at QC11.
The missing absolute-preference colour-sequence branch was ported from the
source formula; both Gacrux and BBP then agreed with the corrected PHP output,
and the complete p7 partition remained exact.

The three 15-player partitions subsequently close at 269,991/269,991 exact
rounds over 29,999 decodable tournaments. Twenty-four deterministic hard-link
shards cover all 30,000 source files exactly once and retain separate runtime,
harness and corpus identities; the aggregate refuses missing oracle evidence.
This establishes sharding as a performance mechanism only, not a change of
input semantics.

## Physical workspace isolation

An immutable replay checkout must be a physical directory, not a symlink or a
copy that preserves a symlink. Before an official campaign starts, the harness
must bootstrap and verify its lock inside that directory; a negative isolation
check must prove that a write in a separate laboratory checkout does not change
the official checkout source hash.

On 2026-08-13 a laboratory directory was created with `cp -a` from a `current`
symlink. The result was another symlink, so later WIP uploads changed the source
tree beneath a running p30 campaign. The final runtime-identity check would
have rejected every shard, and a subsequent p50 launch did reject all 12
workers immediately. The incomplete p30/p50 reports were quarantined and the
campaigns restarted from a physically independent, lock-verified checkout.
No result from the contaminated run is acceptance evidence. Use `cp -aL` (or
archive extraction) when the source can be a symlink, then run a negative
isolation check before spending corpus time.

Two later external WSL shutdowns demonstrated that immutable source identity
is necessary but not sufficient when corpus, shards and reports live in
`/tmp`. Official campaigns now live under `/srv/openpairing-validation`,
finalize each small shard report by atomic rename, and run as boot-enabled work
under a single fail-closed supervisor with `Restart=no`. The unit resumes at
machine boot, but a failed campaign remains stopped for diagnosis instead of
entering an automatic retry loop. It runs campaign and classification stages
strictly in sequence; only a zero exit can advance to the next field size. A
Windows-side WSL keepalive prevents the
distribution lifecycle from stopping systemd when the last SSH client exits.
A restart therefore loses at most the currently running small shards and can
never turn a partial JSON file into evidence. The earlier multi-unit
`OnSuccess` graph is retained as a negative operational control: `Requires=`
re-entered completed oneshots, while fan-out could start the next campaign
before classification had passed.

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

That translation was a necessary but incomplete mitigation. The first encoder
revision sized a packed field from the per-edge range `maximum - minimum`.
After translation, however, a matching accumulates several non-negative edge
components; the field must hold `maximumCardinality * shiftedMaximum`. With an
unmatched vertex or otherwise variable matching cardinality, the narrower
field could carry into the preceding criterion and silently reverse the Dutch
lexicographic order. This was the root cause behind the first p7 corpus
deviations after the global engine had passed the small frozen gate.

The original exhaustive oracle did not expose the defect because it compared
quality vectors before their scalar encoding. Blossom and exhaustive search
could therefore agree on the vector model while both consumed a corrupted
packed weight in the production path. The regression now compares accumulated
encoded weights for competing matchings, including signed components and
variable cardinality, and proves that a lower criterion cannot carry into a
higher one. General rule: an oracle must observe the representation actually
consumed by the system, not only a semantically cleaner representation
upstream. A mitigation that exists is not evidence that it is complete.

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
