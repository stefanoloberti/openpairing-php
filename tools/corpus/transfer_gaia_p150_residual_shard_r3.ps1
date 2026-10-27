param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^s(10[89]|11[01])$')]
    [string] $Shard
)

$ErrorActionPreference = 'Stop'
$sourceAlias = 'gaia@gaia.local'
$primaryAlias = 'doppia-3090'
$sourceRoot = '/home/gaia/openpairing-worker/work/p150-epoch4-86ec9d9-gaia-r3/quarantine'
$activeCampaign = '/srv/openpairing-validation/campaigns/p150-epoch4-86ec9d9'
$campaign = $activeCampaign
$intake = "$campaign/quarantine/cross-intake/$Shard"
$sourceTree = '/srv/openpairing-validation/releases/86ec9d9-r2'
$upstream = '/srv/chesspairings-engine-staging/releases/production-engines-20260810/gacrux'
$importer = '/srv/openpairing-validation/tooling/911bbab/corpus/import_cross_worker_shard.sh'
$expectedHost = 'gaia'
$expectedSource = 'a42e9e60024ce2526e247544f36d39a657163e56e814e9b76c7d34593a48e884'
$expectedHarness = 'a617aa2677cdf3d0ad204caa73b18d6dceceb61b1d2d65ba95f9669a8ab06c4c'
$expectedCommit = '86ec9d9c003d68468906bddf12c69fbcaaf750ac'

function Get-RemoteText {
    param([string] $Alias, [string[]] $Arguments)

    $result = & ssh $Alias @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Remote command failed on $Alias with exit code $LASTEXITCODE."
    }

    return (($result -join "`n").Trim())
}

function Invoke-PrimaryTemporaryBash {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Script,
        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[A-Za-z0-9._-]+$')]
        [string] $Name
    )

    $bashBase64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Script))
    $remoteScript = @'
$ErrorActionPreference = 'Stop'
$temporary = Join-Path $env:USERPROFILE '__NAME__.sh'
$bash = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__BASH_BASE64__'))
try {
    [IO.File]::WriteAllText($temporary, $bash, (New-Object Text.UTF8Encoding($false)))
    $qualified = [IO.Path]::GetFullPath($temporary)
    $drive = $qualified.Substring(0, 1).ToLowerInvariant()
    $linuxTemporary = "/mnt/$drive$($qualified.Substring(2).Replace('\', '/'))"
    & wsl.exe -d ChessPairings-Ubuntu-24.04 -- bash $linuxTemporary
    if ($LASTEXITCODE -ne 0) { throw "Primary temporary script failed with exit code $LASTEXITCODE." }
} finally {
    Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue
}
'@
    $remoteScript = $remoteScript.Replace('__NAME__', $Name).Replace('__BASH_BASE64__', $bashBase64)
    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($remoteScript))
    & ssh $primaryAlias powershell.exe -NoProfile -NonInteractive -EncodedCommand $encoded
    if ($LASTEXITCODE -ne 0) {
        throw "Primary temporary Bash execution failed with exit code $LASTEXITCODE."
    }
}

function Copy-VerifiedBase64 {
    param(
        [string] $SourcePath,
        [string] $DestinationPath,
        [string] $ExpectedSha256,
        [ValidateSet('report', 'attestation')]
        [string] $Kind
    )

    $sourceBase64 = Get-RemoteText $sourceAlias @('base64', '-w', '0', $SourcePath)
    if ($sourceBase64.Length -lt 4) {
        throw "Empty Base64 payload for $Kind."
    }
    $sourceBytes = [Convert]::FromBase64String($sourceBase64)
    $compressed = [IO.MemoryStream]::new()
    $gzip = [IO.Compression.GZipStream]::new(
        $compressed,
        [IO.Compression.CompressionMode]::Compress,
        $true
    )
    try {
        $gzip.Write($sourceBytes, 0, $sourceBytes.Length)
    } finally {
        $gzip.Dispose()
    }
    $base64 = [Convert]::ToBase64String($compressed.ToArray())
    $compressed.Dispose()

    $bashTemplate = @'
set -Eeuo pipefail
destination='__DESTINATION__'
expected='__EXPECTED__'
mkdir -p -- "$(dirname -- "$destination")"
if test -e "$destination"; then
    observed=$(sha256sum "$destination" | awk '{print $1}')
    test "$observed" = "$expected"
else
    temporary_destination="$destination.tmp.$$"
    trap 'rm -f -- "$temporary_destination"' EXIT
    base64 -d '__LINUX_TEMPORARY__' | gzip -dc >"$temporary_destination"
    observed=$(sha256sum "$temporary_destination" | awk '{print $1}')
    test "$observed" = "$expected"
    mv -- "$temporary_destination" "$destination"
    trap - EXIT
fi
sha256sum "$destination"
'@
    $bashTemplate = $bashTemplate.Replace('__DESTINATION__', $DestinationPath).Replace('__EXPECTED__', $ExpectedSha256)
    $bashBase64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($bashTemplate))
    $remoteScript = @'
$ErrorActionPreference = 'Stop'
$payload = ([Console]::In.ReadToEnd() -replace '\s', '')
if ([string]::IsNullOrWhiteSpace($payload)) { throw 'empty Base64 payload' }
$temporary = Join-Path $env:USERPROFILE '__TEMP__'
[IO.File]::WriteAllText($temporary, $payload, [Text.Encoding]::ASCII)
$qualifiedTemporary = [IO.Path]::GetFullPath($temporary)
$drive = $qualifiedTemporary.Substring(0, 1).ToLowerInvariant()
$linuxTemporary = "/mnt/$drive$($qualifiedTemporary.Substring(2).Replace('\', '/'))"
$bash = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__BASH_BASE64__')).Replace('__LINUX_TEMPORARY__', $linuxTemporary)
$scriptTemporary = "$temporary.sh"
try {
    [IO.File]::WriteAllText($scriptTemporary, $bash, (New-Object Text.UTF8Encoding($false)))
    $qualifiedScriptTemporary = [IO.Path]::GetFullPath($scriptTemporary)
    $scriptDrive = $qualifiedScriptTemporary.Substring(0, 1).ToLowerInvariant()
    $linuxScriptTemporary = "/mnt/$scriptDrive$($qualifiedScriptTemporary.Substring(2).Replace('\', '/'))"
    & wsl.exe -d ChessPairings-Ubuntu-24.04 -- bash $linuxScriptTemporary
    if ($LASTEXITCODE -ne 0) { throw "Primary intake failed with exit code $LASTEXITCODE." }
} finally {
    Remove-Item -LiteralPath $temporary, $scriptTemporary -Force -ErrorAction SilentlyContinue
}
'@
    $remoteScript = $remoteScript.Replace('__TEMP__', "openpairing-$Shard-$Kind.b64")
    $remoteScript = $remoteScript.Replace('__BASH_BASE64__', $bashBase64)
    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($remoteScript))

    $startInfo = New-Object Diagnostics.ProcessStartInfo
    $startInfo.FileName = 'ssh.exe'
    $startInfo.Arguments = "$primaryAlias powershell.exe -NoProfile -NonInteractive -EncodedCommand $encoded"
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = [Diagnostics.Process]::Start($startInfo)
    try {
        $standardOutputTask = $process.StandardOutput.ReadToEndAsync()
        $standardErrorTask = $process.StandardError.ReadToEndAsync()
        for ($offset = 0; $offset -lt $base64.Length; $offset += 1024) {
            $length = [Math]::Min(1024, $base64.Length - $offset)
            $process.StandardInput.WriteLine($base64.Substring($offset, $length))
        }
        $process.StandardInput.Close()
        $process.WaitForExit()
        $standardOutput = $standardOutputTask.GetAwaiter().GetResult()
        $standardError = $standardErrorTask.GetAwaiter().GetResult()
        $exitCode = $process.ExitCode
    } finally {
        if (-not $process.HasExited) {
            $process.Kill()
        }
        $process.Dispose()
    }
    if ($standardOutput.Length -gt 0) {
        Write-Output $standardOutput.Trim()
    }
    if ($standardError.Length -gt 0) {
        [Console]::Error.Write($standardError)
    }
    if ($exitCode -ne 0) {
        throw "Verified transfer failed for $Kind with exit code $exitCode."
    }
}

$sourceReport = "$sourceRoot/reports/$Shard.json"
$sourceAttestation = "$sourceRoot/attestations/$Shard.json"
$reportSha = ((Get-RemoteText $sourceAlias @('sha256sum', $sourceReport)) -split '\s+')[0]
$attestationSha = ((Get-RemoteText $sourceAlias @('sha256sum', $sourceAttestation)) -split '\s+')[0]
if ($reportSha -notmatch '^[0-9a-f]{64}$' -or $attestationSha -notmatch '^[0-9a-f]{64}$') {
    throw 'Source report or attestation hash is invalid.'
}

$intakeReport = "$intake/report.json"
$intakeAttestation = "$intake/attestation.json"
Copy-VerifiedBase64 $sourceReport $intakeReport $reportSha 'report'
Copy-VerifiedBase64 $sourceAttestation $intakeAttestation $attestationSha 'attestation'

$primaryCorpus = "/srv/openpairing-validation/campaigns/p150/shards/$Shard"
$importScript = @"
set -Eeuo pipefail
'$importer' '$intakeReport' '$intakeAttestation' '$Shard' '$primaryCorpus' \
  '$sourceTree' '$upstream' '$campaign' '$expectedHost' \
  '$expectedSource' '$expectedHarness' '$expectedCommit'
"@
Invoke-PrimaryTemporaryBash $importScript "openpairing-$Shard-gaia-r3-import"

Write-Output "OPENPAIRING_CROSS_TRANSFER_AND_IMPORT=PASS shard=$Shard reportSha256=$reportSha attestationSha256=$attestationSha"
