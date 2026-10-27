# ADR 0002: fail-closed TRF-2026 individual subset

Status: accepted for milestone 2 checkpoint, 2026-08-11.

The first TRF adapter supports only individual FIDE Dutch records `001`, `012`,
`142`, `152`, `162` and `192`. It accepts `FIDE_DUTCH_2025` (the official code
for the post-30-June-2025 Dutch rules) and its date-dependent alias
`FIDE_DUTCH`; it writes the explicit code. Writer output uses CRLF so every
record includes the CR required by the specification.

Fixed-column `001` data is mapped without binary floats. The supported fields
are TPN, sex, title, name, rating, federation, FIDE ID, birth date, score, rank,
and per-round opponent/colour/result blocks. Canonical IDs equal TPNs on TRF
import because TRF exposes no independent player identifier.

Unknown record types are rejected with `trf.record_unsupported`. This makes the
subset less convenient for complete real-world TRFs but prevents silent data
loss. Later milestones may add records one at a time with explicit model and
round-trip tests. ASCII-only writer output is intentional until encoding rules
and byte-column behaviour are represented explicitly.

The official TRF-2026 PDF snapshot has unresolved approval placeholders on its
cover even though FIDE TEC publishes it as TRF-2026. This editorial anomaly is
recorded; no certification or approval conclusion is inferred from it.

