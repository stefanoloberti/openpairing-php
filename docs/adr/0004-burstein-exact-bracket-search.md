# ADR 0004: Burstein bracket search

Status: accepted, 2026-08-11.

The official FIDE Burstein rules effective 1 February 2026 are normative. BBP
is used only as a development comparison because its own documentation warns
that its Burstein implementation may be imperfect.

Burstein seeding rounds delegate to the Dutch engine. Later brackets rank by
the exact BH/SB opposition index and TPN. The engine enumerates outgoing-floater
sets in C5/C6 order, recursively tests C7 completion, and uses the independent
weighted matcher to optimize additive C8 and the lexicographic BSN vector.
Colour allocation has a separate Burstein implementation because its ranking
and same-absolute-preference rule differ from Dutch.

Brackets up to 22 players use exact matching. Larger brackets retain bounded
backtracking and may return `engine.resource_limit`. No upstream process is
called and no partial pairing is returned.
