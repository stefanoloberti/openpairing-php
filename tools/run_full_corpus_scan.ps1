param(
    [string] $Corpus = 'C:\Users\stefa\Downloads\tournaments',
    [string] $Output = (Join-Path $PSScriptRoot '..\build\trf-corpus-reports-20260812'),
    [int] $Concurrency = 6
)

$ErrorActionPreference = 'Stop'
$php = Join-Path $PSScriptRoot 'runtime\php\php.exe'
$scanner = Join-Path $PSScriptRoot 'scan_trf_corpus.php'
New-Item -ItemType Directory -Force $Output | Out-Null

$queue = [System.Collections.Queue]::new()
Get-ChildItem -Directory $Corpus | Sort-Object Name | ForEach-Object {
    $existingReport = Join-Path $Output ($_.Name + '.json')
    if (!(Test-Path $existingReport) -or (Get-Item $existingReport).Length -eq 0) {
        $queue.Enqueue($_)
    }
}
$running = @()
$failures = @()

while ($queue.Count -gt 0 -or $running.Count -gt 0) {
    while ($queue.Count -gt 0 -and $running.Count -lt $Concurrency) {
        $directory = $queue.Dequeue()
        $stdout = Join-Path $Output ($directory.Name + '.json')
        $stderr = Join-Path $Output ($directory.Name + '.stderr.log')
        $process = Start-Process -FilePath $php -ArgumentList @($scanner, $directory.FullName, '0', '100') `
            -RedirectStandardOutput $stdout -RedirectStandardError $stderr -WindowStyle Hidden -PassThru
        $running += [pscustomobject]@{ Name = $directory.Name; Process = $process }
    }

    Start-Sleep -Milliseconds 200
    $stillRunning = @()
    foreach ($entry in $running) {
        if ($entry.Process.HasExited) {
            if ($entry.Process.ExitCode -ne 0) {
                $failures += $entry.Name
            }
        } else {
            $stillRunning += $entry
        }
    }
    $running = $stillRunning
}

$reports = Get-ChildItem -File -Filter '*.json' $Output | ForEach-Object {
    Get-Content -Raw $_.FullName | ConvertFrom-Json
}
$aggregate = [ordered]@{
    schema = 'openpairing.trf-corpus-aggregate/1'
    corpus = $Corpus
    directories = $reports.Count
    failedDirectories = $failures
    filesSeen = ($reports | ForEach-Object { $_.counts.filesSeen } | Measure-Object -Sum).Sum
    bytesSeen = ($reports | ForEach-Object { $_.counts.bytesSeen } | Measure-Object -Sum).Sum
    decoded = ($reports | ForEach-Object { $_.counts.decoded } | Measure-Object -Sum).Sum
    strictDecoded = ($reports | ForEach-Object { $_.counts.strictDecoded } | Measure-Object -Sum).Sum
    legacyNormalizedDecoded = ($reports | ForEach-Object { $_.counts.legacyNormalizedDecoded } | Measure-Object -Sum).Sum
    roundTrip = ($reports | ForEach-Object { $_.counts.roundTrip } | Measure-Object -Sum).Sum
    diagnostics = [ordered]@{}
    reports = @($reports | ForEach-Object {
        [ordered]@{ root = $_.root; elapsedSeconds = $_.elapsedSeconds; counts = $_.counts; diagnostics = $_.diagnostics }
    })
}

foreach ($report in $reports) {
    if (!($report.diagnostics -is [System.Management.Automation.PSCustomObject])) {
        continue
    }
    foreach ($property in $report.diagnostics.PSObject.Properties) {
        if (!$aggregate.diagnostics.Contains($property.Name)) {
            $aggregate.diagnostics[$property.Name] = 0
        }
        $aggregate.diagnostics[$property.Name] += [int] $property.Value
    }
}

$aggregate | ConvertTo-Json -Depth 10 | Set-Content -Encoding UTF8 (Join-Path $Output 'aggregate.json')
if ($failures.Count -gt 0) { exit 1 }
