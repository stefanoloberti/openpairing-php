# ADR 0003: bounded exact general matching before weighted Blossom

Status: superseded for scalable matching by ADR 0007; retained as test oracle.

The runtime exposes `WeightedGeneralMatchingInterface`. Weights are vectors of
integers, summed exactly and compared lexicographically after cardinality. Edge
and vertex input order cannot decide ties.

`ExactWeightedGeneralMatcher` is a pure-PHP exact dynamic program for arbitrary
(including non-bipartite) graphs. It is deliberately limited to 22 vertices;
the bound controls exponential time, memo memory and recursion depth. Exceeding
it throws a classified `MatchingLimitExceeded` rather than switching to an
heuristic or invoking an upstream executable.

This class does **not** complete BBP parity. ADR 0007 adds the polynomial
weighted Blossom implementation behind the same conceptual boundary. The
bounded exact matcher remains deliberately unchanged as an independent
small-graph oracle for differential verification.
