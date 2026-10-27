param(
    [Parameter(Mandatory)] [string] $Source,
    [Parameter(Mandatory)] [string] $Destination,
    [string] $HostAlias = 'doppia-3090',
    [string] $Distribution = 'ChessPairings-Ubuntu-24.04'
)

$ErrorActionPreference = 'Stop'
$resolved = (Resolve-Path -LiteralPath $Source).Path
$stdout = [System.IO.Path]::GetTempFileName()
$stderr = [System.IO.Path]::GetTempFileName()
try {
    $process = Start-Process ssh.exe -ArgumentList @(
        $HostAlias, 'wsl', '-d', $Distribution, '--',
        'dd', "of=$Destination", 'status=none'
    ) -RedirectStandardInput $resolved -RedirectStandardOutput $stdout `
      -RedirectStandardError $stderr -WindowStyle Hidden -Wait -PassThru
    if ($process.ExitCode -ne 0) {
        throw "Remote stream upload failed: $(Get-Content -Raw $stderr)"
    }
} finally {
    Remove-Item -LiteralPath $stdout, $stderr -Force -ErrorAction SilentlyContinue
}
