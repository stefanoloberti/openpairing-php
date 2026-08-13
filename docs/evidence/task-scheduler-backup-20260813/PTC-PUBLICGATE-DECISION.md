# PTC/PublicGate scheduled-task decision table

This table is derived from the XML definitions exported before any cleanup and
from `inventory-post-phase2.json`. None of these nine tasks was changed in
phase 2.

The important XML finding is uniform: every definition contains an empty
`<Triggers />` element. Therefore none has a time, logon, boot or event trigger
and none can start automatically. `Ready` means the definition remains enabled
for an explicit manual start, not that a future activation is scheduled.

| Task | Trigger type and activation | Action target | Last run / result | Recommendation |
|---|---|---|---|---|
| `ChessPairings-FIDE-PTC5000-PublicGate1` | None — `<Triggers />`; no time/event | `C:\fide-staging\ChessPairings-0.45.4-publicgate-a-ptc\ptc-replay-5000-seed20260818-publicgate1.cmd` | 2026-08-12 10:10:06 CEST / `267014` (`0x41306`, terminated) | **Disable** — historical 0.45.4 PTC replay; retain XML and evidence for a reversible manual restoration. |
| `ChessPairings-FIDE-PTC5000-PublicGate-Parallel1` | None — `<Triggers />`; no time/event | `C:\fide-staging\ChessPairings-0.45.4-publicgate-a-ptc\ptc-replay-5000-seed20260818-publicgate-parallel1.cmd` | 2026-08-12 10:19:00 CEST / `0` | **Disable** — completed parallel replay for a historical 0.45.4 gate. |
| `ChessPairings-FIDE-ValidatePTC5000-Control1` | None — `<Triggers />`; no time/event | `C:\fide-staging\ChessPairings-0.45.4-publicgate-orchestration-20260812\Test-PtcCampaignEvidence-Positive5000.cmd` | 2026-08-12 11:44:08 CEST / `0` | **Disable** — completed positive-control helper; evidence and XML preserve reproducibility. |
| `ChessPairings-FIDE-ValidatePTC5000-Negative1` | None — `<Triggers />`; no time/event | `C:\fide-staging\ChessPairings-0.45.4-publicgate-orchestration-20260812\Test-PtcCampaignEvidence-Negative5000.cmd` | 2026-08-12 11:44:40 CEST / `1` | **Disable** — purpose is explicitly a negative wrong-count control, so the non-zero result is consistent with its role; it has no current trigger. |
| `ChessPairings-FIDE-ArchivePTC5000-Control1` | None — `<Triggers />`; no time/event | `C:\fide-staging\ChessPairings-0.45.4-publicgate-orchestration-20260812\New-PtcEvidenceArchive-Positive5000.cmd` | 2026-08-12 11:50:18 CEST / `0` | **Disable** — completed historical archive-control helper; disabling preserves both script and definition. |
| `ChessPairings-FIDE-ArchiveRTG5000-Control1` | None — `<Triggers />`; no time/event | `C:\fide-staging\ChessPairings-0.45.4-publicgate-orchestration-20260812\New-RtgEvidenceArchive-Positive5000.cmd` | 2026-08-12 11:56:42 CEST / `0` | **Disable** — completed historical archive-control helper; no automatic activation exists. |
| `ChessPairings-FIDE-Archive-PTC5000-PublicGate1` | None — `<Triggers />`; no time/event | `C:\fide-staging\ChessPairings-0.45.4-publicgate-a-ptc\archive-ptc-evidence.cmd` | 2026-08-12 10:53:57 CEST / `0` | **Disable** — completed evidence archive for the historical public gate. |
| `ChessPairings-FIDE-5000-PublicGate1` | None — `<Triggers />`; no time/event | `C:\fide-staging\ChessPairings-0.45.4-publicgate-a\campaign-5000-seed20260818-publicgate1.cmd` | 2026-08-12 08:41:28 CEST / `0` | **Disable** — completed historical 0.45.4 public-gate campaign. |
| `ChessPairings-FIDE-50000-PreRC1` | None — `<Triggers />`; no time/event | `C:\fide-staging\ChessPairings-0.45.4-pre50k-a\campaign-50000-seed20260811-pre50k1.cmd` | 2026-08-11 20:56:48 CEST / `267014` (`0x41306`, terminated) | **Disable** — historical pre-RC campaign ended terminated and has been superseded; retain XML for rollback. |

These recommendations are advisory only. All nine tasks remained enabled and
`Ready` after phase 2, as required by the owner decision boundary.
