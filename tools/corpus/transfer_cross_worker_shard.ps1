param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^s(19[2-9]|2[0-4][0-9]|25[0-5])$')]
    [string] $Shard
)

$ErrorActionPreference = 'Stop'
$sourceAlias = 'python-69'
$sourceDistribution = 'Ubuntu'
$primaryAlias = 'doppia-3090'
$primaryDistribution = 'ChessPairings-Ubuntu-24.04'
$sourceRoot = '/srv/openpairing-cross-worker/work/p80-epoch4-86ec9d9-python69/quarantine'
$campaign = '/srv/openpairing-validation/campaigns/p80-epoch4-86ec9d9'
$intake = "$campaign/quarantine/cross-intake/$Shard"
$sourceTree = '/srv/openpairing-validation/releases/86ec9d9-r2'
$upstream = '/srv/chesspairings-engine-staging/releases/production-engines-20260810/gacrux'
$importer = '/srv/openpairing-validation/tooling/911bbab/corpus/import_cross_worker_shard.sh'
$expectedHost = 'DESKTOP-EF1C0NB'
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

function Copy-VerifiedBase64 {
    param(
        [string] $SourcePath,
        [string] $DestinationPath,
        [string] $ExpectedSha256,
        [ValidateSet('report', 'attestation')]
        [string] $Kind
    )

    $base64 = Get-RemoteText $sourceAlias @(
        'wsl.exe', '-d', $sourceDistribution, '--',
        'base64', '-w', '0', $SourcePath
    )
    if ($base64.Length -lt 4) {
        throw "Empty Base64 payload for $Kind."
    }

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
    base64 -d '__LINUX_TEMPORARY__' >"$temporary_destination"
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
$reportSha = ((Get-RemoteText $sourceAlias @('wsl.exe', '-d', $sourceDistribution, '--', 'sha256sum', $sourceReport)) -split '\s+')[0]
$attestationSha = ((Get-RemoteText $sourceAlias @('wsl.exe', '-d', $sourceDistribution, '--', 'sha256sum', $sourceAttestation)) -split '\s+')[0]
if ($reportSha -notmatch '^[0-9a-f]{64}$' -or $attestationSha -notmatch '^[0-9a-f]{64}$') {
    throw 'Source report or attestation hash is invalid.'
}

$intakeReport = "$intake/report.json"
$intakeAttestation = "$intake/attestation.json"
Copy-VerifiedBase64 $sourceReport $intakeReport $reportSha 'report'
Copy-VerifiedBase64 $sourceAttestation $intakeAttestation $attestationSha 'attestation'

$primaryCorpus = "/srv/openpairing-validation/campaigns/p80/shards/$Shard"
$importArguments = @(
    'wsl.exe', '-d', $primaryDistribution, '--', $importer,
    $intakeReport, $intakeAttestation, $Shard, $primaryCorpus,
    $sourceTree, $upstream, $campaign, $expectedHost,
    $expectedSource, $expectedHarness, $expectedCommit
)
& ssh $primaryAlias @importArguments
if ($LASTEXITCODE -ne 0) {
    throw "Primary import gate failed with exit code $LASTEXITCODE."
}

Write-Output "OPENPAIRING_CROSS_TRANSFER_AND_IMPORT=PASS shard=$Shard reportSha256=$reportSha attestationSha256=$attestationSha"
