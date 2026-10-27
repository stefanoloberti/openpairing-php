# TRF corpus provenance and reproducibility

OpenPairing does not publish or mirror the tournament inputs used by the
historical pairing campaigns. Those synthetic TRF files come from the public
[Gacrux test-tournament archive](https://www.gacrux.no/spp/tournaments/tournaments.zip),
which is described on the [Gacrux website](https://www.gacrux.no/) as a corpus
for Swiss pairing checkers.

The upstream archive inspected on 2026-10-27 had these properties:

- compressed size: `444207412` bytes;
- SHA-256: `9d2092fef940996a36e715e56afb5eac0f671ad802644fc7dfa86bfd938acc44`;
- `123557` TRF files, plus its README and directory entries.

The archive README describes the same named tournament families used by the
OpenPairing campaigns. It does not include a `LICENSE`, `LICENCE`, `COPYING`,
or other explicit redistribution grant. Public availability is therefore not
treated as permission to rehost the payload.

## Recorded OpenPairing selection

The public validation total of 1,633,555 reference-exact rounds comes from
53,201 inputs selected from the upstream archive:

| Campaign | Upstream directories or files | Inputs | Exact rounds |
|---|---|---:|---:|
| p50 | `t_n15_p50_d10_s50`, `t_n31_p50_d10_s50`, `t_n31_p50_d5_s50` | 30,000 | 768,324 |
| p80 | `t_n15_p80_d10_s50`, `t_n51_p80_d10_s50` | 20,000 | 660,000 |
| p150 | `t_n91_p150_d5_s50` | 2,000 | 182,000 |
| acceleration | `acc` | 1,000 | 11,000 |
| p300 | `t_n61_p300_d2_s50` | 200 | 12,200 |
| p600 | `t_n31_p600_d1_s50/T0160.trf` | 1 | 31 |
| **Total** |  | **53,201** | **1,633,555** |

The input count is not a claim that OpenPairing created 53,201 tournaments.
It identifies the Gacrux inputs consumed by the recorded OpenPairing runs. The
round count is an OpenPairing result and is supported by the promoted campaign
reports and classifications described in
`HISTORICAL-PAIRING-VERIFICATION.md`.

## Reproduction policy

To reproduce a campaign:

1. download the archive directly from Gacrux;
2. verify the archive SHA-256 shown above, or record the new upstream hash if
   Gacrux has intentionally replaced the archive;
3. select the directories and the single p600 file listed in the table;
4. run the identity-pinned verification tooling documented in
   `HISTORICAL-PAIRING-VERIFICATION.md`;
5. compare generated reports and classifications with the promoted evidence.

OpenPairing may publish small minimized regression fixtures that are original,
independently licensed, or covered by explicit permission. It does not publish
the 53,201-file Gacrux subset as a separate download.
