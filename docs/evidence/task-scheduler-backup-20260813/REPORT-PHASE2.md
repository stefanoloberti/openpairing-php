# Windows Task Scheduler cleanup — phase 2

Phase 2 was completed on `DESKTOP-3OI0QHR` on 2026-08-13 at 10:31 CEST.
It used the XML rollback definitions exported and committed before phase 1.

## Result

- Relevant definitions before/after: 29 / 29.
- Armed before/after: 16 / 10.
- Disabled before/after: 13 / 19.
- Newly disabled in phase 2: 6.
- Protected and unchanged: 10 (the running WSL keepalive plus nine PTC/PublicGate tasks).
- Deleted tasks: 0.
- Negative control: all six authorized tasks were independently re-queried as
  `Disabled`; all ten protected tasks were independently re-queried as not
  `Disabled`.
- `OpenPairing-WSL-KeepAlive` remained `Running`.

Machine-readable evidence is `disable-result-phase2.json` and
`inventory-post-phase2.json`. The relevant rollback definitions remain in
`xml/`.

## Tasks disabled

| Task | Last result before phase 2 | Reason |
|---|---:|---|
| `ChessPairings-WebStaging-LargeSoak-20260810` | `0` | Completed one-off soak from 2026-08-10 |
| `ChessPairings-WebStaging-Overnight-KeepAlive-20260810` | `0` | Completed one-off staging support from 2026-08-10 |
| `ChessPairings-WebOvernight-Validators-20260810` | `0` | Completed one-off validator run from 2026-08-10 |
| `ChessPairings-SwissChess700K-Anomalies-20260810` | `0` | Completed one-off anomaly scan from 2026-08-10 |
| `ChessPairings-FIDE-200k-Gacrux` | `0` | Historical mass run that could compete with the active corpus campaign |
| `ChessPairings-FIDE-500k-Gacrux-Large` | `2147943467` (`0x8007042B`) | Historical mass run that could compete with the active corpus campaign; its last result was an error |

No task was stopped, edited, unregistered or deleted.

## Remaining armed tasks

The ten remaining armed definitions are exactly the explicitly protected set:

- `OpenPairing-WSL-KeepAlive`;
- the nine PTC/PublicGate definitions listed in
  `PTC-PUBLICGATE-DECISION.md`.
