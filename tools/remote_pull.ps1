param(
    [Parameter(Mandatory)] [string] $Source,
    [Parameter(Mandatory)] [string] $Destination,
    [string] $HostAlias = 'doppia-3090',
    [string] $Distribution = 'ChessPairings-Ubuntu-24.04'
)

$ErrorActionPreference = 'Stop'
$target = [System.IO.Path]::GetFullPath($Destination)
$parent = Split-Path -Parent $target
if ($parent -ne '') {
    New-Item -ItemType Directory -Force $parent | Out-Null
}
$stderr = [System.IO.Path]::GetTempFileName()
try {
    $process = Start-Process ssh.exe -ArgumentList @(
        $HostAlias, 'wsl', '-d', $Distribution, '--', 'cat', $Source
    ) -RedirectStandardOutput $target -RedirectStandardError $stderr `
      -WindowStyle Hidden -Wait -PassThru
    if ($process.ExitCode -ne 0) {
        Remove-Item -LiteralPath $target -Force -ErrorAction SilentlyContinue
        throw "Remote stream download failed: $(Get-Content -Raw $stderr)"
    }
} finally {
    Remove-Item -LiteralPath $stderr -Force -ErrorAction SilentlyContinue
}
