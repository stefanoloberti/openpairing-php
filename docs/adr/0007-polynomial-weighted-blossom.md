# ADR 0007: polynomial weighted Blossom matching

Status: accepted and integrated into the public Dutch engine.

The bounded dynamic-programming matcher remains an independent small-graph
oracle, but it cannot be the production architecture for large Swiss brackets.
The project therefore incorporates a pure-PHP semantic port of BBP Pairings'
Apache-2.0 weighted general-graph matcher at commit
`8f9e3c5ffdc4d7a08a33d31c6fb49b4e7acef4d5`.

`BlossomMaximumWeight` implements a primal-dual Blossom algorithm with outer
and inner labels, exact dual variables, zero-resistance edges, blossom
contraction/expansion and augmenting paths. Its array-backed integer identifiers
replace BBP's C++ memory pools and pointers. `DynamicUInt` replaces BBP's
`DynamicUint` with immutable 30-bit limbs; width is calculated per problem and
is never below 128 bits. Two high guard bits preserve the upstream arithmetic
precondition. Lexicographic vectors and a deterministic pairing-number mate
vector are packed without cross-field carry.

The first correctness gate used exact equality with
`ExactWeightedGeneralMatcher` on 1,000 seeded graphs, targeted odd-cycle
fixtures and input-order invariance. It passed while two bugs remained: delta1
could terminate a maximum-cardinality stage early and a cached `bestEdge` could
survive topology changes. The accepted strengthened gate adds 1,000 sparse
sequences of match, freeze and reweight, comparing every mutation with a fresh
Blossom and the exhaustive optimum. Those sequential tests exposed both bugs.
PHPStan at maximum level and capacity tests at 80 and 300 vertices complete the
gate.
The 300-vertex complete-graph result proves removal of the former architectural
limit, not a latency promise: PHP 8.3.6 in the inspected WSL environment used
approximately 54.1 seconds and 279 MiB.

The deterministic mate-vector contributed most of the roughly 2,700 encoded
bits at 300 vertices. After exact Dutch parity it was removed from the Dutch
weight only, with input-order invariance, 500/500 small-field parity and an
80-player exact round as gates. The measured 80-player improvement was only
about 1.4%, confirming that rematch count, not weight width, is the remaining
dominant cost. Generic matcher callers retain the packed mate-vector default.

Dutch parity and its public-engine switchover are recorded in ADR 0008. The
bounded Dutch engine remains an independent small-field oracle.

ADR 0008 defines that integration as a persistent whole-round graph with
bracket reweighting/finalization, following the control structure actually
observed in BBP `dutch.cpp`; it is not a local matcher substitution.
