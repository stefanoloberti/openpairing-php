# FIDE Baku acceleration

`BakuAcceleration::apply()` implements FIDE C.04.7 Articles 1.2-1.5. It forms
GA from the first half of the active initial order rounded upward to an even
size, awards win-equivalent virtual points for the first half (rounded up) of
the accelerated rounds, halves them for the remaining accelerated rounds and
removes them afterwards. The method rejects scoring unless a win equals two
draws and a loss is zero.

For late entries, pass the canonical player ID of the participant who was the
last member of GA in the previous round. This preserves the identity required
by Article 1.3.2 even if its current TPN changes.

Canonical `Player::score` contains standings points only. `acceleration`
contains only the current virtual points, while `accelerationSchedule` stores
the optional round-indexed schedule and `pairingScore()` returns the current
exact sum. Dutch and Burstein use that sum for scoregroups, internal sorting,
floater score comparisons, PAB order and board order. Burstein opposition
evaluation and final-standing tie-breaks use the real score only.

TRF-2026 record `250` is decoded into that schedule and encoded into canonical
round/player ranges. Conflicting overlaps, unknown competitors, negative
virtual points and ranges beyond the tournament fail closed. A real
192-player Baku corpus case round-trips into the same two ranges and its first
round pairs exactly against the recorded reference. That is a bounded
functional check, not yet a claim over the full 1,000-tournament accelerated
partition. Legacy record `XXA` remains outside the public subset and is
rejected instead of silently losing virtual points.
