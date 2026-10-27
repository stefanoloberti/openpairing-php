param(
    [string] $Corpus = 'C:\Users\stefa\Downloads\tournaments',
    [string] $Output = (Join-Path $PSScriptRoot '..\build\historical-pairing-reports'),
    [int] $Concurrency = 4,
    [int] $MaxFilesPerDirectory = 0,
    [ValidateSet('large-field', 'production', 'legacy')]
    [string] $Mode = 'large-field'
)

$ErrorActionPreference = 'Stop'
$php = Join-Path $PSScriptRoot 'runtime\php\php.exe'
$verifier = Join-Path $PSScriptRoot 'verify_trf_pairings.php'
New-Item -ItemType Directory -Force $Output | Out-Null

$queue = [System.Collections.Queue]::new()
Get-ChildItem -Directory $Corpus | Sort-Object Name | ForEach-Object { $queue.Enqueue($_) }
$running = @()
$failures = @()
while ($queue.Count -gt 0 -or $running.Count -gt 0) {
    while ($queue.Count -gt 0 -and $running.Count -lt $Concurrency) {
        $directory = $queue.Dequeue()
        $stdout = Join-Path $Output ($directory.Name + '.json')
        $stderr = Join-Path $Output ($directory.Name + '.stderr.log')
        $modeArgument = switch ($Mode) {
            'large-field' { '--large-field' }
            'production' { '--production' }
            'legacy' { '' }
        }
        $arguments = @($verifier, $directory.FullName, $MaxFilesPerDirectory)
        if ($modeArgument -ne '') { $arguments += $modeArgument }
        $process = Start-Process -FilePath $php -ArgumentList $arguments `
            -RedirectStandardOutput $stdout -RedirectStandardError $stderr -WindowStyle Hidden -PassThru
        $running += [pscustomobject]@{ Name = $directory.Name; Process = $process }
    }
    Start-Sleep -Milliseconds 200
    $next = @()
    foreach ($entry in $running) {
        if ($entry.Process.HasExited) {
            if ($entry.Process.ExitCode -ne 0) { $failures += $entry.Name }
        } else {
            $next += $entry
        }
    }
    $running = $next
}

$reports = Get-ChildItem -File -Filter '*.json' $Output |
    Where-Object { $_.Name -ne 'aggregate.json' } |
    ForEach-Object { Get-Content -Raw $_.FullName | ConvertFrom-Json }
$identities = @($reports | ForEach-Object { $_.runtimeIdentity.sourceTreeSha256 } | Sort-Object -Unique)
if ($identities.Count -ne 1 -or [string]::IsNullOrWhiteSpace($identities[0])) {
    throw "Historical reports do not share exactly one runtime source-tree identity."
}
$floatSemantics = @($reports | ForEach-Object { $_.historicalFloatSemantics } | Sort-Object -Unique)
if ($floatSemantics.Count -ne 1 -or [string]::IsNullOrWhiteSpace($floatSemantics[0])) {
    throw "Historical reports do not share exactly one historical-float semantic identity."
}
$harnessIdentities = @($reports | ForEach-Object { $_.harnessIdentity.sha256 } | Sort-Object -Unique)
if ($harnessIdentities.Count -ne 1 -or [string]::IsNullOrWhiteSpace($harnessIdentities[0])) {
    throw "Historical reports do not share exactly one replay-harness identity."
}
if ($Mode -eq 'large-field') {
    foreach ($report in $reports) {
        $expectedOracleCases = [int]$report.counts.'largeField.candidateDeviation' + [int]$report.counts.'largeField.candidateColourVariant'
        $retainedOracleCases = @($report.shadowSamples | Where-Object { $_.code -in @('large-field.pairing-deviation', 'large-field.colour-variant') }).Count
        if ($retainedOracleCases -ne $expectedOracleCases) {
            throw "Large-field report $($report.root) retained $retainedOracleCases of $expectedOracleCases required oracle cases."
        }
    }
}
$corpusManifest = [System.Security.Cryptography.IncrementalHash]::CreateHash([System.Security.Cryptography.HashAlgorithmName]::SHA256)
foreach ($report in @($reports | Sort-Object root)) {
    $entry = [System.Text.Encoding]::UTF8.GetBytes(($report.root.Replace('\', '/') + "`0" + $report.corpusIdentity.manifestSha256 + "`n"))
    $corpusManifest.AppendData($entry)
}
$aggregateCorpusHash = ([BitConverter]::ToString($corpusManifest.GetHashAndReset())).Replace('-', '').ToLowerInvariant()
$sum = [ordered]@{}
foreach ($name in @('filesSeen', 'decoded', 'tournamentsVerified', 'tournamentsAllExact', 'roundsAttempted', 'roundsExact', 'roundsSamePairsColourVariant', 'roundsPairingDeviation', 'roundsEngineNoSolution', 'roundsEngineResourceLimit')) {
    $sum[$name] = ($reports | ForEach-Object { $_.counts.$name } | Measure-Object -Sum).Sum
}
[ordered]@{
    schema = 'openpairing.trf-historical-pairing-aggregate/1'
    corpus = $Corpus
    maxFilesPerDirectory = $MaxFilesPerDirectory
    mode = $Mode
    directories = $reports.Count
    failedDirectories = $failures
    runtimeIdentity = $reports[0].runtimeIdentity
    harnessIdentity = $reports[0].harnessIdentity
    historicalFloatSemantics = $floatSemantics[0]
    corpusIdentity = [ordered]@{
        schema = 'openpairing.corpus-aggregate-identity/1'
        directoryCount = $reports.Count
        fileCount = ($reports | ForEach-Object { $_.corpusIdentity.fileCount } | Measure-Object -Sum).Sum
        totalBytes = ($reports | ForEach-Object { $_.corpusIdentity.totalBytes } | Measure-Object -Sum).Sum
        manifestSha256 = $aggregateCorpusHash
    }
    counts = $sum
    reports = @($reports | ForEach-Object { [ordered]@{ root = $_.root; elapsedSeconds = $_.elapsedSeconds; counts = $_.counts; diagnostics = $_.diagnostics } })
} | ConvertTo-Json -Depth 10 | Set-Content -Encoding UTF8 (Join-Path $Output 'aggregate.json')

if ($failures.Count -gt 0) { exit 1 }
