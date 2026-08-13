# Windows Task Scheduler cleanup report

Cleanup completed on `DESKTOP-3OI0QHR` at 2026-08-13 09:35 CEST.

## Result

- Relevant definitions before/after: 29 / 29.
- Armed before/after: 21 / 16.
- Disabled before/after: 8 / 13.
- Band A disabled: 5, all from the 0.45.5 pipeline.
- Band B disabled: 0; every known target exists and repeated failure was not
  established from a single last-result value.
- Band C pending owner decision: 16.
- Deleted tasks: 0.
- Negative control: every Band A task was re-queried and returned `Disabled`.
- `OpenPairing-WSL-KeepAlive` remained enabled and `Running`; the p50 service
  remained active and continued completing shards.

Machine-readable evidence is `inventory-pre.json`, `disable-result.json` and
`inventory-post.json`. The 21 pre-change XML definitions are in `xml/`.

## Band C decision table

| Task | Action / trigger summary | Last run / result | Likely purpose | Recommendation |
|---|---|---|---|---|
| `OpenPairing-WSL-KeepAlive` | WSL `sleep infinity`; at logon | 2026-08-13 07:27 / already running | Keeps the active OpenPairing systemd campaign alive | **Keep enabled during corpus campaign** |
| `ChessPairings-FIDE-200k-Gacrux` | `Run-200k-GacruxCampaign.ps1`; one-time 2026-08-09 | 2026-08-09 12:01 / 0 | Historical Gacrux corpus campaign | Disable if no longer needed |
| `ChessPairings-FIDE-500k-Gacrux-Large` | same runner, 500k large; one-time 2026-08-09 | 2026-08-09 16:23 / `2147943467` | Historical large Gacrux campaign | Disable if no longer needed; inspect historical failure first |
| `ChessPairings-SwissChess700K-Anomalies-20260810` | encoded SwissChess anomaly scan; one-time 2026-08-10 | 2026-08-10 16:10 / 0 | Historical SwissChess diagnostic | Disable if evidence is already archived |
| `ChessPairings-WebOvernight-Validators-20260810` | web validators; one-time 2026-08-10 | 2026-08-10 21:00 / 0 | Historical web-staging validation | Confirm production/staging ownership |
| `ChessPairings-WebStaging-LargeSoak-20260810` | web-staging keepalive; no next run | 2026-08-10 18:20 / 0 | Staging soak support | Confirm whether staging still relies on it |
| `ChessPairings-WebStaging-Overnight-KeepAlive-20260810` | encoded web-staging keepalive; one-time 2026-08-10 | 2026-08-10 21:05 / 0 | Historical overnight staging support | Confirm whether staging still relies on it |
| `ChessPairings-FIDE-50000-PreRC1` | 0.45.4 pre-RC campaign | 2026-08-11 20:56 / `267014` | Historical pre-RC gate | Recommend disable after owner confirmation |
| `ChessPairings-FIDE-5000-PublicGate1` | 0.45.4 5k public gate | 2026-08-12 08:41 / 0 | Historical public gate | Recommend disable after owner confirmation |
| `ChessPairings-FIDE-PTC5000-PublicGate1` | 0.45.4 PTC replay | 2026-08-12 10:10 / `267014` | Historical public-gate replay | Recommend disable after owner confirmation |
| `ChessPairings-FIDE-PTC5000-PublicGate-Parallel1` | parallel 0.45.4 PTC replay | 2026-08-12 10:19 / 0 | Historical public-gate replay | Recommend disable after owner confirmation |
| `ChessPairings-FIDE-Archive-PTC5000-PublicGate1` | archive PTC evidence | 2026-08-12 10:53 / 0 | Evidence archive helper | Confirm evidence archive is complete |
| `ChessPairings-FIDE-ValidatePTC5000-Control1` | positive PTC evidence validator | 2026-08-12 11:44 / 0 | Historical control | Recommend disable after owner confirmation |
| `ChessPairings-FIDE-ValidatePTC5000-Negative1` | negative PTC validator | 2026-08-12 11:44 / 1 | Expected negative control or failure | Clarify expected exit code before disabling |
| `ChessPairings-FIDE-ArchivePTC5000-Control1` | positive PTC archive helper | 2026-08-12 11:50 / 0 | Historical control archive | Confirm archive is complete |
| `ChessPairings-FIDE-ArchiveRTG5000-Control1` | positive RTG archive helper | 2026-08-12 11:56 / 0 | Historical control archive | Confirm archive is complete |

No Band C task was stopped, disabled, edited or deleted.
