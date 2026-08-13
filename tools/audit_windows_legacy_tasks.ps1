[CmdletBinding()]
param(
    [string] $Output = 'C:\ChessPairingsFideStress\evidence\openpairing-windows-legacy-tasks.json'
)

$ErrorActionPreference = 'Stop'
$patterns = @(
    'ChessPairings',
    'fide-staging',
    'ChessPairingsFideStress',
    '0\.45\.5',
    '0455',
    'GacruxCampaign'
)
$matcher = '(?i)(' + ($patterns -join '|') + ')'

$items = foreach ($task in Get-ScheduledTask) {
    $actions = @($task.Actions | ForEach-Object {
        [ordered]@{
            execute = $_.Execute
            arguments = $_.Arguments
            workingDirectory = $_.WorkingDirectory
        }
    })
    $haystack = @(
        $task.TaskName,
        $task.TaskPath,
        ($actions | ConvertTo-Json -Compress -Depth 4)
    ) -join ' '
    if ($haystack -notmatch $matcher) {
        continue
    }

    $info = $task | Get-ScheduledTaskInfo
    $triggers = @($task.Triggers | ForEach-Object {
        [ordered]@{
            type = $_.CimClass.CimClassName
            enabled = if ($null -eq $_.Enabled) { $true } else { [bool]$_.Enabled }
            startBoundary = $_.StartBoundary
            endBoundary = $_.EndBoundary
            userId = $_.UserId
            repetitionInterval = $_.Repetition.Interval
            repetitionDuration = $_.Repetition.Duration
        }
    })
    $enabledTriggers = @($triggers | Where-Object enabled).Count
    $state = [string]$task.State

    [ordered]@{
        taskName = $task.TaskName
        taskPath = $task.TaskPath
        state = $state
        armed = $state -ne 'Disabled' -and $enabledTriggers -gt 0
        enabledTriggerCount = $enabledTriggers
        actions = $actions
        triggers = $triggers
        principal = [ordered]@{
            userId = $task.Principal.UserId
            logonType = [string]$task.Principal.LogonType
            runLevel = [string]$task.Principal.RunLevel
        }
        lastRun = if ($null -eq $info.LastRunTime) { $null } else { $info.LastRunTime.ToString('o') }
        lastResult = $info.LastTaskResult
        nextRun = if ($null -eq $info.NextRunTime) { $null } else { $info.NextRunTime.ToString('o') }
    }
}

$ordered = @($items | Sort-Object taskPath, taskName)
$document = [ordered]@{
    schema = 'openpairing-windows-legacy-task-inventory/v1'
    collectedAt = (Get-Date).ToString('o')
    computer = $env:COMPUTERNAME
    readOnly = $true
    matcher = $patterns
    relevantTaskCount = $ordered.Count
    armedTaskCount = @($ordered | Where-Object armed).Count
    disabledTaskCount = @($ordered | Where-Object { $_.state -eq 'Disabled' }).Count
    note = 'Inventory only. No task was disabled, stopped, deleted or modified.'
    tasks = $ordered
}

$parent = Split-Path -Parent $Output
New-Item -ItemType Directory -Force -Path $parent | Out-Null
$document | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $Output -Encoding UTF8
$document | ConvertTo-Json -Depth 12
