param(
    [Parameter(Mandatory)] [string] $Script,
    [string] $HostAlias = 'doppia-3090',
    [string] $Distribution = 'ChessPairings-Ubuntu-24.04'
)

$ErrorActionPreference = 'Stop'
$resolved = (Resolve-Path -LiteralPath $Script).Path
$remote = '/tmp/openpairing-run-' + [Guid]::NewGuid().ToString('N') + '.sh'
& (Join-Path $PSScriptRoot 'remote_push.ps1') -Source $resolved `
  -Destination $remote -HostAlias $HostAlias -Distribution $Distribution
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
& ssh.exe $HostAlias wsl -d $Distribution -- bash $remote
$exitCode = $LASTEXITCODE
& ssh.exe $HostAlias wsl -d $Distribution -- rm -f $remote | Out-Null
if ($exitCode -ne 0) {
    throw "Remote WSL script failed with exit code $exitCode."
}
