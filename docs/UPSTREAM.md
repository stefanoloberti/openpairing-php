# Upstream inventory

Acquired on 2026-08-11 (Europe/Rome). Copies are development-only, live under
`tools/upstream`, are ignored by the project Git repository, and were clean
immediately after acquisition.

| Source | URL | Ref | Commit | Licence |
|---|---|---|---|---|
| Gacrux/TieBreakServer | https://github.com/OttoMilvang/TieBreakServer | `main` | `14a34a2c2f36509b110e4f25d6247f31fc4bf2f5` | MIT |
| BBP Pairings | https://github.com/BieremaBoyzProgramming/bbpPairings | `master` | `8f9e3c5ffdc4d7a08a33d31c6fb49b4e7acef4d5` | Apache-2.0 |
| FIDE Dutch rules | https://handbook.fide.com/chapter/C0403202602 | effective 2026-02-01 | HTML snapshot | FIDE publication; reference only |
| FIDE basic Swiss rules | https://handbook.fide.com/chapter/C0401202507 | effective 2026-02-01 | HTML snapshot | FIDE publication; reference only |
| FIDE general handling rules | https://handbook.fide.com/chapter/GeneralHandlingRulesForSwissTournaments202602 | effective 2026-02-01 | HTML snapshot | FIDE publication; reference only |
| FIDE Burstein rules | https://handbook.fide.com/chapter/C040402202602 | effective 2026-02-01 | HTML snapshot | FIDE publication; reference only |
| FIDE play-off and tie-break regulations | https://handbook.fide.com/chapter/TieBreakRegulations032026 | effective 2026-03-01 | Official live page inspected 2026-08-12; mandatory descriptor PDF snapshot | FIDE publication; reference only |
| FIDE rating tables | https://handbook.fide.com/chapter/B022024 | effective 2024-03-01, current tables inspected 2026-08-12 | Official live page, tables 8.1.1-8.1.2 | FIDE publication; reference only |
| FIDE-approved accelerated systems | https://handbook.fide.com/chapter/C0407202602 | effective 2026-02-01 | Official live page inspected 2026-08-12; local snapshot unavailable during network restriction | FIDE publication; reference only |
| FIDE electronic equipment and THP technical specifications | https://handbook.fide.com/chapter/ChessEquipmentWithElectronicComponenets032026 | effective 2026-03-01 | Official live page inspected 2026-08-15 | FIDE publication; reference only |
| FIDE general product acceptance regulations | https://handbook.fide.com/chapter/GeneralRulesAndRegulations032026 | effective 2026-03-01 | Official live page inspected 2026-08-15 | FIDE publication; reference only |
| TRF-2026 | https://tec.fide.com/wp-content/uploads/2025/04/TRF-2026.pdf | downloaded 2026-08-11 | PDF snapshot | FIDE TEC publication; reference only |
| TRF26 tournament-type table | https://tec.fide.com/wp-content/uploads/2025/01/TournamentTypeCodeTable192_jan_2025.pdf | downloaded 2026-08-11 | PDF snapshot | FIDE TEC publication; reference only |

## Important SHA-256 hashes

### Gacrux

| File | SHA-256 |
|---|---|
| `LICENSE` | `51c1270eaa39f39dd54ddbbc070507f813edb9c0cdd14eec1c6910d255b15a32` |
| `pairing.py` | `a3439ad470137fa15e2222173a603251f5cb1557bac427e84b82a902dc338516` |
| `pairingdutch.py` | `7d50aa6a52ce06cdd5ab882915800767a6c642e4c80c3b9bd5d859b3d535844c` |
| `pairingchecker.py` | `954cc28241b5a4227ab4559cd88384732eecfc77a736c239fec232644fc00c5c` |
| `chessjson.py` | `9f9e0a4021087b864aa6e8d5b2b4174590ea7d322fb3d3d50092b5ab1a4e35ee` |
| `trf2json.py` | `fd1e10e64713f472126655ef7abb3124fdbbee76f6a707128ad46d1df09485f6` |
| `qdefs.py` | `456c24376a48521e06c32e0297e2e5461a962acc2eb83dee7cc1bdf124d5bd26` |
| `crosstabledutch.py` | `42a597e393feba7bce2620b5fd8584e86a311e38c38c0e0b2691942e6190e8b0` |

### BBP Pairings

| File | SHA-256 |
|---|---|
| `LICENSE.txt` | `52aff3690066d4d7e54affcc120edfa302b04c5f7d3d1d90363ee5ecb1795681` |
| `Apache-2.0.txt` | `3ddf9be5c28fe27dad143a5dc76eea25222ad1dd68934a047064e56ed2fa40c5` |
| `src/swisssystems/dutch.cpp` | `40a1dccf69c70c3b38e3cbfd1daa7d42f49b63556dd24564fdab1de9fc12f2eb` |
| `src/swisssystems/burstein.cpp` | `d3c2c3b0bc734e98ebc2cef1fdad0de36d8452bd6e7ab2042c90d107074bedd5` |
| `src/matching/computer.cpp` | `7246e2d40b8628ef99ad6bc60b9aae27cf765f76c1817a45fa80494db49a0087` |
| `src/fileformats/trf.cpp` | `d8c540de7388078a295813b8f2f5ae9f47b7aed3eccd781db0a1d83ab9df2792` |
| locally built `bbpPairings.exe` | `1dff7412b71cb1e2acba99c483b30cae9dfe1a3ac935697c9820970daa7a4421` |

The BBP executable is a development-only Linux x86-64 build produced with the
upstream Makefile in local WSL Ubuntu. Its reported version is
`non-release build v6.0.0-5-g8f9e3c5`; build products are upstream-ignored and
both upstream worktrees remain clean.

### FIDE snapshots

| File | SHA-256 |
|---|---|
| `C0401-basic-rules-2026.html` | `a2bfa612d113f3c539ed165896c82d8eceb3e6e54bc4c9a4ebbde5f526d042e2` |
| `C0402-general-handling-2026.html` | `0b6b7691a3fafd40efd58be093558c5357b8a7eb2d1765eae5b3950b69e9e5d5` |
| `C0403-dutch-2026.html` | `8c90eb082562fe0ebe97e33955a284c9df53f0ac43170e9a844ec1021111ef03` |
| `C040402-burstein-2026.html` | `8c53a0f2024de7701624c42e260d3a91af321ed80d6d20fce2036f2c14c3c7e0` |
| `TRF-2026.pdf` | `0fa6bf9f301963637d5209f1770cab4a3847bf002cfeebdf8f40f2d1c17bd845` |
| `MandatoryTieBreaks-TRF26.pdf` | `5c3b75483fc75b39325e92d18d221dfeeb60012726ff5f4fc14037587c4f3aee` |
| `TournamentTypeCodeTable192-TRF26.pdf` | `5513ac8d70cdd36c74468b4a300b78b962385623dd22b57aefac543c45084e4f` |
