# Windows Task Scheduler backup before cleanup

Collected from `DESKTOP-3OI0QHR` at `2026-08-13T09:31:56+02:00`, before any
task was changed.

`inventory-pre.json` records all 29 task definitions matched by the existing
ChessPairings/FIDE laboratory inventory. Exactly 21 were armed. The `xml/`
directory contains an `Export-ScheduledTask` XML definition for every one of
those 21 armed tasks. The inventory also records state, trigger, principal,
last run/result, decoded PowerShell arguments where applicable, action target
and target existence.

## Authorized classification before change

### Band A: disable

These five armed tasks belong to the 0.45.5 pipeline:

- `ChessPairings-FIDE-0455-Acceptance5000-1`
- `ChessPairings-FIDE-0455-ArchivePipelineSmoke-1`
- `ChessPairings-FIDE-0455-ArchivePipelineSmoke6-1`
- `ChessPairings-FIDE-0455-ArchivePipelineSmoke12-1`
- `ChessPairings-FIDE-0455-ArchivePipelineSmoke12-2`

Three other 0.45.5 task definitions were already disabled before this cleanup,
including `ChessPairings-FIDE-0455-Sandbox-Smoke-After-Acceptance`, the task
associated with the explicit sandbox credential-repair shutdown at 05:33.
They are inventoried but will not be modified.

### Band B: disable if inert

Empty. Every identified action target exists. A single non-zero last result is
not treated as proof of repeated failure.

### Band C: await owner decision

The remaining 16 armed tasks are not modified. They include current
OpenPairing WSL keepalive, web staging/validator tasks, older 0.45.4 gates,
Gacrux campaigns and a SwissChess anomaly campaign. Their full actions,
triggers and last results are in `inventory-pre.json`; the post-change report
will provide a concise decision table.

## Rollback

Re-enable a definition without changing it:

```powershell
Enable-ScheduledTask -TaskName '<task name>' -TaskPath '\'
```

If the task definition itself ever needs restoring, re-import its XML under
the same name/path with `Register-ScheduledTask`. No task or script is deleted
by this cleanup.

## Phase 2

After an explicit owner decision, phase 2 disabled six additional historical
one-off or mass-run definitions. `REPORT-PHASE2.md`,
`disable-result-phase2.json` and `inventory-post-phase2.json` attest the
operation and its independent post-query. The armed count moved from 16 to 10;
no definition was deleted.

The WSL keepalive and all nine PTC/PublicGate tasks were protected from the
operation. `PTC-PUBLICGATE-DECISION.md` extracts their actual trigger and
action definitions from the backed-up XML for a separate owner decision.
