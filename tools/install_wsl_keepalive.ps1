[CmdletBinding()]
param(
    [string] $Distribution = 'ChessPairings-Ubuntu-24.04',
    [string] $TaskName = 'OpenPairing-WSL-KeepAlive'
)

$ErrorActionPreference = 'Stop'
if ($Distribution -notmatch '^[A-Za-z0-9._-]+$') {
    throw "Unsafe WSL distribution name: $Distribution"
}
if ($TaskName -notmatch '^OpenPairing-[A-Za-z0-9._-]+$') {
    throw "Unsafe task name: $TaskName"
}

$localAccount = Get-CimInstance Win32_UserAccount `
    -Filter "LocalAccount=True AND Name='$env:USERNAME'" | Select-Object -First 1
if ($null -eq $localAccount) {
    throw "Cannot resolve local Windows account for $env:USERNAME."
}
$user = "$env:COMPUTERNAME\$($localAccount.Name)"

$knownDistributions = @(& "$env:WINDIR\System32\wsl.exe" --list --quiet) |
    ForEach-Object { $_.Trim([char]0).Trim() } |
    Where-Object { $_ -ne '' }
if ($Distribution -notin $knownDistributions) {
    throw "WSL distribution is not installed: $Distribution"
}

$action = New-ScheduledTaskAction `
    -Execute "$env:WINDIR\System32\wsl.exe" `
    -Argument "-d $Distribution --exec /bin/sleep infinity"
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $user
$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit ([timespan]::Zero) `
    -MultipleInstances IgnoreNew `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
    -Principal $principal -Settings $settings -Description `
    'Keeps the OpenPairing laboratory WSL distribution alive while its persistent corpus service runs.' `
    -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName
Start-Sleep -Seconds 2

$task = Get-ScheduledTask -TaskName $TaskName
$info = $task | Get-ScheduledTaskInfo
if ($task.State -ne 'Running') {
    throw "WSL keepalive task did not remain running: $($task.State)"
}
[pscustomobject]@{
    sentinel = 'OPENPAIRING_WSL_KEEPALIVE_PASS'
    task = $TaskName
    distribution = $Distribution
    state = [string] $task.State
    user = $user
    lastRun = $info.LastRunTime.ToString('o')
    lastResult = $info.LastTaskResult
    action = "$($task.Actions[0].Execute) $($task.Actions[0].Arguments)"
} | ConvertTo-Json -Depth 5
