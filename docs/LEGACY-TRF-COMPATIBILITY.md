# TRF06/TRF16 compatibility audit

Audit date: 2026-08-16. This is a design and scope record, not a claim that
the current PTC accepts legacy TRF files.

## Normative layouts inspected

The official two-page TRF06 and TRF16 annexes were inspected visually against
their fixed-column tables. Both formats share the core `001` player layout:
TPN in columns 5-8, identity/rating fields through column 79, reported score
in 81-84, rank in 86-89, and ten-column round blocks beginning at column 92.
They also share tournament records `012` through `132` and the historical
`013` team section.

TRF06 defines played `1`, `=` and `0`, plus `+`, `-` and an unpaired blank.
TRF16 retains those codes and adds `W`, `D`, `L`, `H`, `F`, `U` and `Z`, with
blank bye equivalent to `Z`. Neither annex defines the later TRF26 semantic
records needed by the current public decoder, including the pairing-system
record `192`.

The exact source hashes and observed page/layout facts are preserved in
`evidence/legacy-trf-layout-audit-20260816.json`.

## Current executable behavior

The shared `001` columns and the TRF16 result alphabet overlap substantially
with `Trf2026Codec`, but overlap is not compatibility. The current public
decoder requires exactly one `192` and supports only its declared individual
TRF26 subset. Therefore an unmodified TRF06 or TRF16 file is rejected
fail-closed instead of being silently treated as Dutch 2026. The public PTC
documentation correctly says that it accepts TRF26.

This is a product-format gap, not evidence of a pairing-engine error. It also
does not invalidate corpus results produced from explicitly normalized,
identity-attested historical inputs.

## Required compatibility boundary

A future exact candidate may close the legacy path only if it:

1. identifies the input as TRF06 or TRF16 explicitly or by a deterministic,
   attested detector that cannot confuse TRF26;
2. requires an explicit pairing-system/edition selection when the legacy file
   does not encode it; it must never assume Dutch merely to obtain a result;
3. preserves the original input SHA-256 and reports every normalization;
4. maps the version-specific result alphabet without adding colour or
   opponent history to unplayed games;
5. infers round count and initial colour only when the fixed-column history is
   sufficient, otherwise fails with a typed input error;
6. rejects team input, unknown records and semantics outside the declared
   individual scope before pairing;
7. emits the detected source version, selected pairing system, normalization
   list and exact engine identity in the PTC report; and
8. proves positive and negative fixtures for both versions, including CR-only
   line endings, blank/zero byes, forfeits, `W/D/L`, `H/F/U/Z`, odd fields,
   missing first-round colour and ambiguous pairing-system selection.

The safest public interface is an explicit legacy mode such as
`--input-version=trf16 --pairing-system=...`; automatic conversion is not a
substitute for missing semantic data. Export compatibility requires a
separate loss analysis because TRF26 records cannot all be represented in the
older layouts.

## Identity and sequencing decision

No codec or engine source is changed during the active RC8 corpus campaign.
Legacy support belongs to the next exact package candidate unless TEC accepts
the documented fail-closed scope. Any implementation must receive a new
package/runtime identity; if it changes `src/`, it also changes the
source-tree identity and the affected gates must be rerun rather than
relabeled.

The gate remains open until implementation evidence exists on the exact
submitted product or TEC confirms in writing that the declared legacy scope is
sufficient.
