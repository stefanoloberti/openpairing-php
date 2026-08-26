# Oracle-first porting and large-scale validation of a FIDE Dutch pairing engine

> Status: outline for review, not a submission draft.
>
> Snapshot: 2026-08-26. Campaign results that do not yet have a promoted,
> immutable aggregate are deliberately marked `[PENDING]`.

## Working-title alternatives

1. **Oracle-first porting and large-scale validation of a FIDE Dutch pairing engine**
2. **Fail-closed semantic porting with independent oracles: a FIDE Dutch case study**
3. **Evidence before equivalence: human-directed AI agents port a normative pairing engine**
4. **From plausible parity to attested parity: oracle-first development of a PHP pairing engine**

The first title is the clearest for an ICGA audience. The third makes the
human-AI contribution most visible for a software-engineering audience. The
final title is intentionally deferred until the paper's results and venue are
fixed.

## Claim discipline

- The contribution is the method, not the fact that a pairing engine was
  written in PHP. OpenPairingPHP is the case study through which the method is
  evaluated.
- Every quantitative result must cite a promoted evidence JSON and the
  SHA-256 of that exact file. Narrative summaries are navigation aids, never
  numerical sources.
- A running partition is described only as in progress, with a dated snapshot.
  It is not extrapolated into a closed gate.
- Parser coverage, capacity pilots, historical pairing replay, accelerated
  replay, cross-host replication and product shadow validation are separate
  claims with separate identities and denominators.
- The project is independent. It is not certified, endorsed or approved by
  FIDE; arbiters remain responsible for checking and publishing pairings.
- Gacrux/TieBreakServer and its corpus are credited to Otto Milvang and
  gacrux.no under the licence and provenance terms recorded in `NOTICE`.
- Human-AI collaboration is described explicitly as part of the method. It is
  neither hidden nor presented as autonomous authorship.

## Abstract

Normative software ports can look correct while silently diverging in rare
state transitions, historical reconstruction or infrastructure. This paper
will describe an oracle-first method for porting a FIDE Dutch pairing engine
to PHP under human direction with AI coding agents. The method combines
independent output oracles, pinned primary-source mechanisms, semantic epochs,
cryptographic identity, negative controls and fail-closed evidence promotion.

The completed abstract will state only results present in the final evidence
register. `[PENDING: final closed-partition table]` `[PENDING: cross-host
semantic comparison]` `[PENDING: product shadow status]`

### Evidence planned for this section

- `docs/ORACLE-FIRST-METHOD.md`
- `docs/CORPUS-GATE.md`
- `[PENDING: final campaign aggregate JSON and SHA-256]`
- `[PENDING: cross-host semantic comparison JSON and SHA-256]`

## 1. Introduction

FIDE Dutch pairing is a normative stateful procedure rather than a simple
matching formula. A port can reproduce common outputs while being wrong about
the history, lifecycle or priority rule that produced them. The introduction
will frame the engineering problem as preserving meaning under translation,
not merely translating syntax or matching a small regression suite.

OpenPairingPHP provides the concrete setting: a deterministic offline PHP
library and CLI, developed independently from the existing C++ and Python
tools used as development references. The paper will distinguish runtime
independence from development-time oracle use and will state the FIDE and
licence disclaimers without qualification.

### Evidence planned for this section

- `README.md`
- `NOTICE`
- `docs/UPSTREAM.md`
- `docs/LICENSE-PROVENANCE.md`
- `docs/PORTING-MAP.md`

## 2. Architecture of the port

The architecture section will summarize, rather than duplicate, the accepted
design records. The public Dutch engine uses one persistent whole-round graph,
exact lexicographic weights and a polynomial weighted Blossom matcher. MDP
selection, progressive freezing, remainder state, exchange state, colour
allocation and the PAB are treated as a coupled lifecycle.

The bounded implementation remains an independent small-field oracle. This
separation is important: a production architecture and an oracle can share a
specification without sharing the same implementation strategy or failure
mode.

### 2.1 Exact integer arithmetic as an identity dimension

The lexicographic objective is encoded as an exact integer rather than an
approximate scalar score. The port therefore maintains two arithmetic paths:
an always-available immutable 30-bit-limb implementation and an optional GMP
implementation. The selected backend is part of runtime and evidence identity,
not an interchangeable deployment detail. Backend parity is exercised across
1,000 sequential freeze/reweight stress seeds, not only isolated random
graphs.

The same proof obligation governs optimization. A residual matching result is
reused only when its certificate still proves the same optimum; the promoted
performance checkpoint compares 1,000 unchanged residual sequences against a
fresh Blossom solve and the bounded exhaustive oracle. The deterministic
mate-vector experiment will be discussed as a negative performance control,
but its reported percentage and encoded width will not enter the paper until
the exact promoted A/B evidence record is located.

### Evidence planned for this section

- `docs/adr/0007-polynomial-weighted-blossom.md`
- `docs/adr/0008-dutch-global-persistent-matching.md`
- `docs/CANONICAL-JSON.md`
- `docs/PORTING-MAP.md`
- `docs/evidence/pairing-epoch2-dutch-500.json`
  - SHA-256: `dbdd9b5fa08c5eb82b7a7af3d0e16dab8f80b15ca2a9b84cb2c75bb00254a6bd`
- `docs/evidence/cross-worker-python69-bootstrap-20260815.json`
  - SHA-256: `835e3e30bd5df4913bb75f0016592b53ee53d3ba17d44960bcc993bb7fb3ea11`
- `docs/evidence/performance-residual-cache-p80-round1.json`
  - SHA-256: `bc2f6903e84a4d047da0d95dcf43a4df94758e851108b9f9124108118a65ecf9`
- `docs/evidence/p80-round1-gmp-jit-on.json`
  - SHA-256: `fee2ff0cefbf85a381b4165ccd85572a34f3eec9c3ddb508bf90d4e828f95fd7`
- `docs/evidence/p80-round1-limbs-jit-on.json`
  - SHA-256: `91bff59e4759bb16212ad3ab0198601784ea7da2c4cf4117e14ac5747e658c1c`
- `[PENDING: locate the promoted deterministic mate-vector A/B JSON before
  quoting its percentage or encoded bit width]`

## 3. The oracle-first method

This section will turn the repository's operating rules into a reusable
method. It will cover independent oracles, discriminating counterexamples,
comparator identity, non-empty execution controls, physical workspace
isolation, immutable source/harness/corpus identity, exact denominator
reconciliation and semantic epoch resets.

The three oracle roles will be separated. Gacrux classifies the normative
quality of complete alternatives; BBP supplies an independently implemented
primary-source mechanism and result comparator; the bounded exhaustive solver
checks small matching problems without relying on the polynomial matcher.
Agreement is useful only when the compared systems do not share the relevant
failure mode.

### Evidence planned for this section

- `docs/ORACLE-FIRST-METHOD.md`
- `docs/TOOLCHAIN.md`
- `docs/HISTORICAL-PAIRING-VERIFICATION.md`
- `docs/evidence/cross-worker-final-channel-negative-20260817.json`
  - SHA-256: `5ec30df6ce28be23824cf2e000a04b7c14ff30cd318f2bc95eb3de9d2cb35378`
- `docs/evidence/negative-p50-remainder-pairs-recompute-20260813.json`
  - SHA-256: `d0c1785aa9a035aa2673c5e19e67c18e457ba5770a539e41f731a841859dcc30`

## 4. Plausible conclusions disproved by evidence

This is the central methodological section. Each subsection will use the same
four-part form: plausible hypothesis, why it was reasonable, the smallest
discriminating test, and the rule added to the method. Reviewer-proposed
hypotheses are included and attributed as independent review, not silently
absorbed into the implementation narrative.

Sections 4.1 and 4.2 will remain explicitly narrative unless their original
records can be reconstructed from hash-verifiable archives and Git history.
An honest evidence gap is preferable to a retrospective record that merely
looks immutable.

### 4.1 “These deviations may be legitimate equivalent variants”

The hypothesis arose because two deterministic implementations can lawfully
select different pairings. Independent quality classification rejected the
specific alternatives rather than granting them a convenient equivalence
bucket. Resulting rule: no non-exact result is self-certifying; every such
result requires an oracle verdict or remains open.

Evidence: `[PENDING: locate and promote the exact epoch-2 residual-classifier
JSON used for this claim; do not substitute a narrative counter]`.

### 4.2 “The HE/HO correction had no effect”

The apparent negative result came from a stale Composer autoload target rather
than from the corrected source. Resulting rule: runtime source identity and
autoload provenance are part of every report; a result from the wrong checkout
is infrastructure evidence, not a semantic verdict.

Evidence: `[PENDING: locate or promote the immutable stale-runtime-attestation
record; narrative source only: docs/ORACLE-FIRST-METHOD.md]`.

### 4.3 “The within-score-group PAB residuals are a C9-only defect”

The hypothesis was reasonable because the alternatives differed at the PAB
endpoint. Counterfactual Gacrux classifications produced several different
first-quality losses, disproving a single-criterion explanation. Resulting
rule: a first-difference label identifies the first measurable loss, not the
earlier decision that caused it; mechanism claims require the full lifecycle
or pinned primary source.

Evidence:

- `docs/evidence/bbp-shadow-epoch2-v3-unmatched-pab-residuals.json`
  - SHA-256: `0dc3cf43ac7bb56013ec8e58c85ffadddcee9f23382d061ce7b0c624e14c32e9`
- `docs/evidence/pairing-epoch2-lifecycle-c9-494.json`
  - SHA-256: `3b865589cdc3a4bc6965dac09f6b91ec6d6315724353b0771619cb1979dd8711`
- `docs/evidence/gacrux-lifecycle-c9-494-oracle.json`
  - SHA-256: `25fe9bd90711f4fb8cd0143899d8cfc1ec64111820fa7011c506a26025e75c27`

### 4.4 “A historical PAB is not a float”

The replay harness had reconstructed the PAB as no historical float. A
minimized TRF checked against Gacrux showed that it is a downfloat for the next
round, invalidating the prior baselines. Resulting rule: semantic reconstruction
belongs to the evidence identity, and a corrected interpretation starts a new
epoch rather than rewriting history.

Evidence:

- `docs/evidence/pairing-epoch2-dutch-500.json`
  - SHA-256: `dbdd9b5fa08c5eb82b7a7af3d0e16dab8f80b15ca2a9b84cb2c75bb00254a6bd`
- `docs/evidence/pairing-epoch2-v3-pab-unmatched-490.json`
  - SHA-256: `c77b71674da4249503988e4a48496197368a5120f98be09c1037c6d314d39e87`

### 4.5 “The p80 colour variants come from forfeited-game colour history”

This independent-review hypothesis connected the new symptom to an earlier
forfeit-with-opponent defect. A controlled replay changed only the missing
initial-colour record and removed the variants; the verifier's silent fallback,
not the engine's colour allocation, was responsible. Resulting rule: reproduce
the smallest input provenance change before modifying engine semantics, and
retain reviewer hypotheses as negative evidence when disproved.

Evidence:

- `docs/evidence/p80-initial-colour-provenance-20260815.json`
  - SHA-256: `3e10b15721f4d8ade4f594be02cdff9919ae6be6457858aae508ca03d4d205e4`

### 4.6 “The engine regressed on the second host”

Matching source, harness and corpus hashes made this diagnosis initially
credible. The diagnostic command had selected the retained comparator instead
of the production engine. Resulting rule: comparator mode is part of evidence
identity, and a negative launch control must prove which executable path ran.

Evidence:

- `docs/evidence/cross-worker-python69-bootstrap-20260815.json`
  - SHA-256: `835e3e30bd5df4913bb75f0016592b53ee53d3ba17d44960bcc993bb7fb3ea11`

### 4.7 “The missing exchange phase caused the observed p50 deviations”

Primary-source comparison proved that a lifecycle phase was absent, making the
hypothesis structurally plausible. A narrow negative experiment did not change
the affected results; the discriminating fix was round-indexed float expiry.
Resulting rule: a source-level structural difference is not causal evidence;
causality requires a counterexample that changes the disputed outcome.

Evidence:

- `docs/evidence/negative-p50-remainder-pairs-recompute-20260813.json`
  - SHA-256: `d0c1785aa9a035aa2673c5e19e67c18e457ba5770a539e41f731a841859dcc30`
- `docs/evidence/p50-float-age-round-31.json`
  - SHA-256: `b31579876cfcc223f3a9b39891f100407797d2acd5b8affa61cbec49973d0f97`

### 4.8 “Thirteen hours without a closed p80 shard proved a slowdown”

This operational hypothesis used promotion cadence as a throughput signal and
reconstructed the expected baseline from memory. The read-only diagnostic
instead compared the active worker with every preserved report from the
superseded run, verified CPU and arithmetic/JIT identity, and showed that the
empty report directory was still inside the measured completion window.
Resulting rule: campaign ETA and stall alarms must be derived from attested
shard-internal timings and effective concurrency, never from remembered units
or the interval between atomic promotions. This operational example is also a
control for the monitoring-bias threat in Section 9.

Evidence:

- `docs/evidence/p80-throughput-diagnostic-20260816.json`
  - SHA-256: `7c7d58f19cae9717602bc24ede5cd33f1f12d403ed9b61256a354c023971663f`

## 5. Validation campaign

The final paper will present one table whose rows are semantic partitions and
whose columns are denominator, attempted rounds, exact results, classified
non-exact results, failures, source identity, harness identity, corpus identity
and evidence-file hash. Capacity pilots and parser scans will be displayed in
separate tables so that they cannot inflate pairing-verification claims.

The following rows are intentionally incomplete in this outline:

| Evidence lane | Paper status | Required source |
|---|---|---|
| Closed historical partitions before p80 | Ready for extraction, not yet copied into prose | Promoted aggregate JSONs below |
| p80 | Closed: 20,000 tournaments; 660,000/660,000 exact rounds; zero anomaly categories; 256/256 `PASS_NO_ORACLE_CASES` classifications | `docs/evidence/p80-full-86ec9d9-20260826.json`, SHA-256 `e191f3fe27c4afb75144da2a145467034cc3b10f03fdd4f3732b3d8ea7617533`; promotion receipt below |
| p150 and misc | `[PENDING: campaign not closed]` | `[PENDING: path and SHA-256]` |
| Accelerated corpus | `[PENDING: campaign not closed]` | `[PENDING: path and SHA-256]` |
| Uniform replay under final identity | `[PENDING: not executed]` | `[PENDING: path and SHA-256]` |
| Cross-host semantic replication | `[PENDING: comparison script and promoted result]` | `[PENDING: path and SHA-256]` |
| Product shadow | `[PENDING: product gate]` | `[PENDING: path and SHA-256]` |

### Existing evidence anchors

- `docs/evidence/pairing-corpus-p7-full-4a93b7a.json`
  - SHA-256: `2290632976881b86c85aa1c0d36eb9b181c70312f6368cfa08fb94b0533e9f14`
- `docs/evidence/pairing-corpus-p9-full-14539a6.json`
  - SHA-256: `b2a6fb197f09fa3aadca2091776cf8349f2851ddc1f1ca6a49725385b123cdb7`
- `docs/evidence/pairing-corpus-p15-sharded-14539a6.json`
  - SHA-256: `3921775949717166781dd839912b94ea8fbaab40ef3c65f68cf00ba83c0a783e`
- `docs/evidence/pairing-corpus-p30-full-c48fd05.json`
  - SHA-256: `43f7abac47c6354e2968987bb685666a9380f05d071fab51776b4983f2146cbe`
- `docs/evidence/p50-full-dcf92df-20260815.json`
  - SHA-256: `aaa6bec112d9103664bf10b4242f8ce8b627eb635532fd026ffb2304cb2c5b2b`
- `docs/evidence/p80-full-86ec9d9-20260826.json`
  - SHA-256: `e191f3fe27c4afb75144da2a145467034cc3b10f03fdd4f3732b3d8ea7617533`
- `docs/evidence/p80-full-86ec9d9-20260826-receipt.json`
  - SHA-256: `f050f2e6f4b7e0a12ec010436fe6048c52e789c1c8a6e0a14730bccb3389a2ec`

The p80 closure row above is extracted directly from its promoted aggregate
and reconciliation receipt. Remaining counters will be extracted by a small
deterministic paper-table generator so that the prose cannot drift from the
evidence.

## 6. Infrastructure incidents as evidence for fail-closed design

This section will report infrastructure failures honestly: wrong runner or
comparator selection, stale autoload, symlink or checkout identity, cross-host
transport, incomplete report/classification pairs and denominator mismatch.
The claim is not that the campaign infrastructure never failed. The claim to
test is that failures were prevented from becoming promoted semantic evidence.

### Evidence planned for this section

- `docs/ORACLE-FIRST-METHOD.md`
- `docs/CORPUS-GATE.md`
- `docs/evidence/cross-worker-final-channel-negative-20260817.json`
  - SHA-256: `5ec30df6ce28be23824cf2e000a04b7c14ff30cd318f2bc95eb3de9d2cb35378`
- `docs/evidence/cross-worker-first-accepted-import-20260817.json`
  - SHA-256: `b0c51685fa80e43decc028f5f820e48a5d24184cf040b9208d08aaa507a93c86`
- `[PENDING: promoted runner-identity incident record]`
- `[PENDING: promoted denominator-reconciliation record]`

## 7. Limitations and future work

The paper will distinguish semantic correctness from performance and product
readiness. It will state the measured boundary by field size only where an
attested benchmark exists, retain the mapped-but-not-yet-required exchange
debt, and describe the open p150/misc, accelerated, uniform replay and product
shadow lanes without predicting their verdicts.

The product front is not hypothetical, but it is not closed. The pairing-path
inventory is frozen and source-only ChessPairings candidates contain an
isolated adapter, pre-execution attempt ledger and synthetic observation
coordinator. The shadow contract and its fail-closed verifier semantics are
implemented and checkpointed. No live product call site has started official
shadow evidence, the transaction/rollback boundary remains open, and the
shadow verifier therefore still reports `NO_GO`. The paper will preserve that
distinction between source/tooling progress and a closed product gate.

### Evidence planned for this section

- `docs/BENCHMARKS.md`
- `docs/FIDE-SUBMISSION-READINESS.md`
- `docs/SHADOW-VALIDATION.md`
- `docs/CHESSPAIRINGS-INTEGRATION-INVENTORY.md`
- `docs/evidence/chesspairings-pairing-path-inventory-20260816.json`
  - SHA-256: `3bd441ffdfb74b9544fbd673c64aef5edf0fcd58558e12315ff86cc64c5f36db`
- `docs/evidence/chesspairings-shadow-adapter-bootstrap-20260816.json`
  - SHA-256: `bab73a5913d0589b1c456aa220168aafd84975fe221798ebdd3e31342ccc80a2`
- `docs/evidence/chesspairings-shadow-attempt-ledger-checkpoint-20260816.json`
  - SHA-256: `cfd9ff502b97dd3f9604c0bb2eea496580ee02c0bbfbc754e4f8674f9f3156f2`
- `docs/evidence/chesspairings-shadow-observation-coordinator-checkpoint-20260816.json`
  - SHA-256: `a8f4d7a070c7a2e3d78e76e1ee0736cb2ab447bbce12e64d20c0002cf4aba8b7`
- `docs/evidence/shadow-evidence-verifier-semantics-checkpoint-20260816.json`
  - SHA-256: `06ec515a2065c749c155d7438ee97e813322374c486d685e81d9996e0f7112bf`
- `[PENDING: exact integrated-candidate live shadow batch and SHA-256]`

## 8. Human-AI collaboration as an engineering control

The project was developed by AI coding agents, principally Codex, under human
direction and with independent review by a second model. The human operator
set scope, authorized mutations, preserved campaign boundaries and decided
which evidence could be promoted. Agents implemented, inspected, minimized,
tested and documented changes, but no model output was treated as an oracle.

The section will analyze the collaboration protocol itself: explicit identity,
small counterfactuals, reviewer hypotheses, negative evidence, independent
checks and the separation between ordinary debugging sessions and immutable
campaign monitoring. It will avoid anthropomorphic claims and will report the
models and roles only to the level supported by retained task records.

### Evidence planned for this section

- `docs/ORACLE-FIRST-METHOD.md`
- `docs/evidence/autonomy-window-handoff-20260816.json`
- `docs/evidence/autonomy-closeout-1700-20260816.json`
- `[PENDING: concise human-AI contribution ledger with privacy review]`

## 9. Threats to validity

Threats will include oracle correlation, incomplete coverage of rare normative
states, corpus provenance and representativeness, replay-state reconstruction,
runtime and backend variation, evidence-selection bias, and the risk that an
AI-generated plausible explanation influences the next experiment. Each threat
will be paired with the control used and the residual risk that remains.

### Evidence planned for this section

- `docs/ORACLE-FIRST-METHOD.md`
- `docs/TOOLCHAIN.md`
- `docs/CORPUS-GATE.md`
- `docs/CONTROLLED-ENVIRONMENT.md`

## 10. Conclusion

The conclusion will be written only after the final evidence table. It will
argue for a transferable engineering discipline: do not reward plausible
equivalence, do not infer causality from a criterion label or source diff, and
do not let infrastructure success masquerade as semantic evidence.

`[PENDING: final claims after closed campaign and product-shadow review]`

## Appendices planned

- **Appendix A — Evidence register:** path, schema, SHA-256, Git commit, scope
  and paper claims for every cited JSON.
- **Appendix B — Partition table generator:** deterministic extraction from
  promoted aggregates; generated Markdown and LaTeX must be reproducible.
- **Appendix C — False-track counterexamples:** minimized inputs and the exact
  negative-control command for each episode.
- **Appendix D — Human-AI contribution ledger:** roles, review boundaries and
  disclosure text suitable for the selected venue.
- **Appendix E — Reproducibility package:** released source/runtime archives,
  corpus-acquisition instructions and licence notices without redistributing
  private or non-redistributable material.

## Drafting order

1. Keep this outline reviewable while p80 and subsequent machine lanes run.
2. Complete product API inventory and SPEC shadow before expanding paper prose.
3. Write Sections 3, 4 and 8 in machine-idle editorial windows.
4. Generate Section 5 only from promoted aggregate JSONs.
5. Write the abstract and conclusion last.
6. Choose ICGA Journal, arXiv cs.SE or both only after the full draft exists.
