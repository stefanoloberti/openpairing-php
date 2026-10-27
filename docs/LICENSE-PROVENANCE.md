# Licence and provenance matrix

This is a technical provenance assessment, not legal advice.

| Material | Intended treatment | Licence/notice | Current status |
|---|---|---|---|
| `src/**`, `tests/**`, schema, CLI and benchmark | Original implementation informed by published rules | Apache-2.0 | Current runtime and test code |
| Gacrux oracle bridge | Separate development adapter invoking an unmodified local checkout | Bridge Apache-2.0; Gacrux remains MIT | Development only |
| Gacrux `crosstabledutch.py` quality updates | File-level provenance in `DutchQualityModel`; immutable PHP representation and tests documented in `PORTING-MAP.md` | MIT; Copyright (c) 2024 FIDE retained in source and NOTICE | Semantic port incorporated at commit `14a34a2c` |
| BBP weighted Blossom architecture | File-level provenance in `BlossomMaximumWeight` and `DynamicUInt`; modified PHP representation and tests documented in `PORTING-MAP.md` | Apache-2.0; Copyright 2016-2026 Jeremy Bierema retained in NOTICE | Semantic port incorporated at commit `8f9e3c5` |
| FIDE rule/TRF snapshots | Reference material only; excluded from distribution by `.gitignore` | No relicensing claimed | Acquired, not copied into runtime |

The Gacrux licence was read in full before implementation. It requests a link
to https://www.gacrux.no/ when embedded in a product; the link is present in
`NOTICE` and will remain in any about/information view. BBP's `LICENSE.txt` and
full `Apache-2.0.txt` were also read in full.

## Public-release checklist

Before making the repository or a release public:

1. confirm `LICENSE`, `NOTICE` and this matrix are in the source archive;
2. confirm `LICENSE` and `NOTICE` are in the runtime archive;
3. confirm no `tools/upstream` file is packaged;
4. verify the pinned upstream commits and provenance headers still match
   `PORTING-MAP.md` and `UPSTREAM.md`;
5. retain the FIDE non-affiliation disclaimer in the first README section; and
6. obtain qualified legal review if the final distribution is commercial or
   otherwise higher-risk.

No name, logo, or statement in this repository claims FIDE approval. Any
additional source-level port must gain a provenance header and an entry in
`PORTING-MAP.md` before integration.
