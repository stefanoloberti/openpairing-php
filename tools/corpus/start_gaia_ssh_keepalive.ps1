param()

$ErrorActionPreference = 'Stop'
$sshPath = Join-Path $env:WINDIR 'System32\OpenSSH\ssh.exe'
$target = 'gaia@gaia.local'

if (-not (Test-Path -LiteralPath $sshPath -PathType Leaf)) {
    throw "OpenSSH client not found at $sshPath."
}

$existing = Get-CimInstance Win32_Process -Filter "Name = 'ssh.exe'" |
    Where-Object {
        $_.CommandLine -like '*ServerAliveInterval=30*' -and
        $_.CommandLine -like "*$target*"
    } |
    Select-Object -First 1
if ($null -ne $existing) {
    Write-Output "OPENPAIRING_GAIA_SSH_KEEPALIVE=ALREADY_RUNNING pid=$($existing.ProcessId) target=$target"
    exit 0
}

$arguments = @(
    '-N',
    '-T',
    '-o', 'BatchMode=yes',
    '-o', 'ConnectTimeout=8',
    '-o', 'ServerAliveInterval=30',
    '-o', 'ServerAliveCountMax=3',
    $target
)
$process = Start-Process -FilePath $sshPath -ArgumentList $arguments -WindowStyle Hidden -PassThru
Start-Sleep -Seconds 2
if ($process.HasExited) {
    throw "Gaia SSH keepalive exited immediately with code $($process.ExitCode)."
}

Write-Output "OPENPAIRING_GAIA_SSH_KEEPALIVE=RUNNING pid=$($process.Id) target=$target"
