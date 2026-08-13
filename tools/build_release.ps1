param(
    [string] $Version = '0.9.0-rc1',
    [string] $Php = (Join-Path $PSScriptRoot 'runtime\php\php.exe'),
    [string] $ComposerPhar = (Join-Path $PSScriptRoot '..\build\composer.phar'),
    [string] $OutputDirectory = (Join-Path $PSScriptRoot '..\build\release')
)

$ErrorActionPreference = 'Stop'
$repository = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpExecutable = (Resolve-Path -LiteralPath $Php).Path
$composer = (Resolve-Path -LiteralPath $ComposerPhar).Path
$output = [System.IO.Path]::GetFullPath($OutputDirectory)
$buildRoot = [System.IO.Path]::GetFullPath((Join-Path $repository 'build'))
if (-not $output.StartsWith($buildRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Release output must remain below the repository build directory.'
}

Push-Location $repository
try {
    $dirty = (& git status --porcelain=v1)
    if ($LASTEXITCODE -ne 0 -or $dirty) {
        throw 'Official releases require a clean committed tree.'
    }
    $commit = (& git rev-parse HEAD).Trim()
    $commitTimestamp = [DateTimeOffset]::Parse((& git show -s --format=%cI HEAD).Trim())
    $package = "openpairing-php-$Version"

    New-Item -ItemType Directory -Force $output | Out-Null
    $sourceArchive = Join-Path $output "$package-source.zip"
    $runtimeArchive = Join-Path $output "$package-runtime.zip"
    Remove-Item -LiteralPath $sourceArchive, $runtimeArchive -Force -ErrorAction SilentlyContinue

    & git archive --format=zip "--prefix=$package/" "--output=$sourceArchive" HEAD
    if ($LASTEXITCODE -ne 0) { throw 'git archive failed.' }

    $temporary = Join-Path $buildRoot ('.release-' + [Guid]::NewGuid().ToString('N'))
    $stage = Join-Path $temporary $package
    $verification = Join-Path $temporary 'verify'
    New-Item -ItemType Directory -Force $stage, $verification | Out-Null
    try {
        $tar = Join-Path $temporary 'source.tar'
        & git archive --format=tar "--output=$tar" HEAD
        if ($LASTEXITCODE -ne 0) { throw 'git runtime-stage archive failed.' }
        & tar.exe -xf $tar -C $stage
        if ($LASTEXITCODE -ne 0) { throw 'Could not expand the runtime stage.' }

        & $phpExecutable $composer install --working-dir=$stage --no-dev --classmap-authoritative --no-interaction --no-progress
        if ($LASTEXITCODE -ne 0) { throw 'Composer production install failed.' }

        foreach ($relative in @('tests', 'tools', 'docs\evidence', 'docs\adr', 'composer.lock', 'phpstan.neon', 'phpunit.xml.dist', 'CONTRIBUTING.md', 'CODE_OF_CONDUCT.md')) {
            $target = Join-Path $stage $relative
            if (Test-Path -LiteralPath $target) { Remove-Item -LiteralPath $target -Recurse -Force }
        }

        $runtimeComposerPath = Join-Path $stage 'composer.json'
        $runtimeComposer = Get-Content -Raw -LiteralPath $runtimeComposerPath | ConvertFrom-Json
        foreach ($developmentKey in @('require-dev', 'autoload-dev', 'scripts')) {
            $runtimeComposer.PSObject.Properties.Remove($developmentKey)
        }
        $runtimeComposer.description = 'Deterministic, offline FIDE pairing engine for PHP (prebuilt runtime)'
        [System.IO.File]::WriteAllText(
            $runtimeComposerPath,
            ($runtimeComposer | ConvertTo-Json -Depth 16) + "`n",
            [System.Text.UTF8Encoding]::new($false)
        )

        $metadata = [ordered]@{
            schema = 'openpairing.release-metadata/1'
            package = $package
            version = $Version
            gitCommit = $commit
            commitTimestamp = $commitTimestamp.ToString('o')
            minimumPhp = '8.3'
            recommendedExtension = 'ext-gmp'
        }
        [System.IO.File]::WriteAllText(
            (Join-Path $stage 'RELEASE-METADATA.json'),
            ($metadata | ConvertTo-Json -Depth 4) + "`n",
            [System.Text.UTF8Encoding]::new($false)
        )

        Add-Type -AssemblyName System.IO.Compression
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        $stream = [System.IO.File]::Open($runtimeArchive, [System.IO.FileMode]::CreateNew)
        try {
            $zip = [System.IO.Compression.ZipArchive]::new($stream, [System.IO.Compression.ZipArchiveMode]::Create, $false)
            try {
                $files = Get-ChildItem -LiteralPath $stage -File -Recurse | Sort-Object FullName
                foreach ($file in $files) {
                    $relative = $file.FullName.Substring($stage.Length + 1).Replace('\', '/')
                    $entry = $zip.CreateEntry("$package/$relative", [System.IO.Compression.CompressionLevel]::Optimal)
                    $entry.LastWriteTime = $commitTimestamp
                    $input = $file.OpenRead()
                    $destination = $entry.Open()
                    try { $input.CopyTo($destination) } finally { $destination.Dispose(); $input.Dispose() }
                }
            } finally { $zip.Dispose() }
        } finally { $stream.Dispose() }

        Expand-Archive -LiteralPath $runtimeArchive -DestinationPath $verification
        $runtimeRoot = Join-Path $verification $package
        foreach ($forbidden in @('tests', 'tools', 'vendor\phpunit', 'vendor\phpstan')) {
            if (Test-Path -LiteralPath (Join-Path $runtimeRoot $forbidden)) {
                throw "Runtime contains forbidden development path: $forbidden"
            }
        }
        $verifiedComposer = Get-Content -Raw -LiteralPath (Join-Path $runtimeRoot 'composer.json') | ConvertFrom-Json
        foreach ($developmentKey in @('require-dev', 'autoload-dev', 'scripts')) {
            if ($verifiedComposer.PSObject.Properties.Name -contains $developmentKey) {
                throw "Runtime composer.json retains development key: $developmentKey"
            }
        }
        if (Test-Path -LiteralPath (Join-Path $runtimeRoot 'composer.lock')) {
            throw 'Runtime retains the source development lock file.'
        }
        & $phpExecutable $composer validate --no-check-publish --no-interaction (Join-Path $runtimeRoot 'composer.json')
        if ($LASTEXITCODE -ne 0) { throw 'Runtime composer.json validation failed.' }
        & $phpExecutable (Join-Path $runtimeRoot 'bin\openpairing.php') --help | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Runtime CLI help smoke test failed.' }
        $smoke = & $phpExecutable (Join-Path $runtimeRoot 'bin\openpairing.php') (Join-Path $runtimeRoot 'examples\pairing-round-1.json')
        if ($LASTEXITCODE -ne 0) { throw 'Runtime pairing smoke test failed.' }
        $smoke | ConvertFrom-Json | Out-Null
        $standingsSmoke = & $phpExecutable (Join-Path $runtimeRoot 'bin\openpairing.php') --standings '--tie-breaks=DE/P,BH/C1/P,SB/C1' (Join-Path $runtimeRoot 'examples\standings-round-2.json')
        if ($LASTEXITCODE -ne 0) { throw 'Runtime standings smoke test failed.' }
        $standingsResult = $standingsSmoke | ConvertFrom-Json
        if ($standingsResult.status -ne 'ok' -or @($standingsResult.standings).Count -ne 4) {
            throw 'Runtime standings smoke output is incomplete.'
        }

        $artifacts = foreach ($archive in @($sourceArchive, $runtimeArchive)) {
            $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant()
            "$hash  $([System.IO.Path]::GetFileName($archive))" | Set-Content -Encoding ASCII ($archive + '.sha256')
            [ordered]@{ file = [System.IO.Path]::GetFileName($archive); bytes = (Get-Item $archive).Length; sha256 = $hash }
        }
        $report = [ordered]@{ schema = 'openpairing.release-build-report/1'; version = $Version; gitCommit = $commit; artifacts = @($artifacts) }
        [System.IO.File]::WriteAllText(
            (Join-Path $output "$package-build-report.json"),
            ($report | ConvertTo-Json -Depth 5) + "`n",
            [System.Text.UTF8Encoding]::new($false)
        )
    } finally {
        $resolvedTemporary = [System.IO.Path]::GetFullPath($temporary)
        if ($resolvedTemporary.StartsWith($buildRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $resolvedTemporary -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
} finally {
    Pop-Location
}
