<?php

declare(strict_types=1);

/**
 * Independently reconcile an immutable p150/misc/accel campaign before
 * promoting its aggregate. Read-only on the campaign; writes only the named
 * receipt outside the campaign directory.
 *
 * Usage: php verify_partition_promotion.php p150|misc|accel /absolute/receipt.json
 */

date_default_timezone_set('Europe/Rome');

$specifications = [
    'p150' => [
        'epoch' => 'p150-epoch4-86ec9d9',
        'shards' => 128,
        'files' => 2000,
        'rounds' => 182000,
        'exact' => 182000,
        'deviations' => 0,
        'allExact' => 2000,
        'aggregateSha256' => '492fa4f73c0ccc6b3f2d3137e41a97c19a04ece65a1ad8af05d70484391858c8',
        'runnerSha256' => 'dc29b45df5d38b8fc065834b0121ab9936b00860ed6db51a59057e03ccab8ee3',
    ],
    'misc' => [
        'epoch' => 'misc-epoch4-86ec9d9',
        'shards' => 1,
        'files' => 10,
        'rounds' => 381,
        'exact' => 378,
        'deviations' => 3,
        'allExact' => 8,
        'aggregateSha256' => '7ac92446a96baf99458a8de027df49fb66d9d26071ae1a8d446a7aa32a26106f',
        'runnerSha256' => '179293fcce7355fe5e8db13163d91d6b2e01fe97c5e4611d5a534a592b2a1f55',
    ],
    'accel' => [
        'epoch' => 'accel-epoch5-virtual-float',
        'shards' => 64,
        'files' => 1000,
        'rounds' => 11000,
        'exact' => 11000,
        'deviations' => 0,
        'allExact' => 1000,
        'aggregateSha256' => '3c64f91513e2d7b17ab6681de72ec4cbe99644bd3f31ba9925902a4efd135901',
        'runnerSha256' => '1473522a8a811da307701a4993814e4455f38848cbaaab90e6c8c43d076a4459',
    ],
];

function requireThat(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    requireThat(is_file($path) && filesize($path) > 0, 'Missing or empty JSON: '.$path);
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    requireThat(is_array($value), 'Expected JSON object: '.$path);

    return $value;
}

/** @param array<string, mixed> $value */
function equalIdentity(array $value, array $expected, string $label): void
{
    requireThat($value === $expected, 'Mixed '.$label.' identity');
}

/** @param array<string, mixed> $value */
function equalRuntimeIdentity(array $value, array $expected, string $name): void
{
    foreach (['schema', 'engineVersion', 'engineSha256', 'sourceTreeSha256', 'sourceFileCount', 'arithmeticBackend', 'gmpAvailable'] as $field) {
        requireThat(isset($value[$field]) && $value[$field] === ($expected[$field] ?? null), 'Mixed runtime '.$field.': '.$name);
    }
    requireThat(($value['loadedEnginePath'] ?? null) === ($value['expectedEnginePath'] ?? null), 'Runtime engine path mismatch: '.$name);
}

try {
    $partition = $argv[1] ?? '';
    $receiptPath = $argv[2] ?? '';
    requireThat(isset($specifications[$partition]), 'Expected partition p150, misc or accel');
    requireThat(str_starts_with($receiptPath, '/') && !str_starts_with($receiptPath, '/srv/openpairing-validation/campaigns/'), 'Receipt must be outside campaign');
    $spec = $specifications[$partition];
    $base = '/srv/openpairing-validation';
    $campaign = $base.'/campaigns/'.$spec['epoch'];
    $aggregatePath = $campaign.'/aggregate.json';
    $aggregate = readJson($aggregatePath);
    requireThat(hash_file('sha256', $aggregatePath) === $spec['aggregateSha256'], 'Aggregate SHA-256 drift');
    requireThat(($aggregate['schema'] ?? null) === 'openpairing.trf-sharded-aggregate/1', 'Aggregate schema mismatch');
    requireThat(($aggregate['shards'] ?? null) === $spec['shards'], 'Aggregate shard denominator mismatch');
    requireThat(($aggregate['diagnostics'] ?? null) === [], 'Aggregate diagnostics are not empty');
    requireThat(($aggregate['runtimeIdentity']['engineSha256'] ?? null) === '464088b49fec2854eaff5923f777c6c3f05d6256677d0d43efa0c6a509d9c8f2', 'Engine identity mismatch');
    requireThat(($aggregate['runtimeIdentity']['sourceTreeSha256'] ?? null) === 'a42e9e60024ce2526e247544f36d39a657163e56e814e9b76c7d34593a48e884', 'Source identity mismatch');
    requireThat(($aggregate['runtimeIdentity']['arithmeticBackend'] ?? null) === 'gmp', 'Backend mismatch');
    $expectedHarnessSha256 = $partition === 'accel'
        ? '1c8013f591a81e5d57393340466a4c2cec1ddac6ac87b027b6dcdbc9e58d829b'
        : 'a617aa2677cdf3d0ad204caa73b18d6dceceb61b1d2d65ba95f9669a8ab06c4c';
    requireThat(($aggregate['harnessIdentity']['sha256'] ?? null) === $expectedHarnessSha256, 'Harness identity mismatch');
    requireThat(($aggregate['historicalFloatSemantics'] ?? null) === 'fide-dutch-float/3-round-indexed', 'Float semantics mismatch');
    requireThat(($aggregate['corpusIdentity']['fileCount'] ?? null) === $spec['files'], 'Corpus file denominator mismatch');
    requireThat(count($aggregate['reports'] ?? []) === $spec['shards'], 'Aggregate report count mismatch');

    $runnerPath = $base.'/bin/openpairing-campaign-'.$spec['epoch'].'.sh';
    $gateRelease = $partition === 'accel' ? 'accel-epoch5-virtual-float' : '86ec9d9-r2';
    $gatePath = $base.'/releases/'.$gateRelease.'/tools/corpus/classify_shard_fail_fast.sh';
    requireThat(hash_file('sha256', $runnerPath) === $spec['runnerSha256'], 'Installed campaign runner hash mismatch');
    $gateSha256 = '5c4c9c3b5c7785be05bfcd52c87fe6fa9dfe6761c947b403d805e9c0b4973a18';
    requireThat(hash_file('sha256', $gatePath) === $gateSha256, 'Installed Gacrux gate hash mismatch');

    $expectedNames = [];
    for ($i = 0; $i < $spec['shards']; ++$i) {
        $expectedNames[] = sprintf('s%03d.json', $i);
    }
    foreach (['reports', 'classifications'] as $directory) {
        $names = array_values(array_diff(scandir($campaign.'/'.$directory) ?: [], ['.', '..']));
        sort($names);
        requireThat($names === $expectedNames, 'Incomplete or unexpected '.$directory.' basename set');
    }

    $summaries = [];
    foreach ($aggregate['reports'] as $summary) {
        requireThat(is_array($summary) && isset($summary['root']), 'Malformed aggregate report summary');
        requireThat(!isset($summaries[$summary['root']]), 'Duplicate aggregate report root');
        $summaries[$summary['root']] = $summary;
    }

    $totals = array_fill_keys(array_keys($aggregate['counts'] ?? []), 0);
    $verdicts = [];
    $classified = 0;
    $runtimeRoots = [];
    $importedNames = [];
    $importedHosts = [];
    foreach ($expectedNames as $name) {
        $reportPath = $campaign.'/reports/'.$name;
        $classificationPath = $campaign.'/classifications/'.$name;
        $report = readJson($reportPath);
        $classification = readJson($classificationPath);
        requireThat(($report['productionOnly'] ?? null) === true && ($report['largeFieldAudit'] ?? null) === true, 'Unapproved report mode: '.$name);
        requireThat(($report['diagnostics'] ?? null) === [], 'Nonempty report diagnostics: '.$name);
        requireThat(($report['elapsedSeconds'] ?? 0) > 0, 'Missing terminal elapsedSeconds: '.$name);
        equalRuntimeIdentity($report['runtimeIdentity'] ?? [], $aggregate['runtimeIdentity'], $name);
        $runtimeRoots[$report['runtimeIdentity']['projectRoot']] = true;
        equalIdentity($report['harnessIdentity'] ?? [], $aggregate['harnessIdentity'], 'harness');
        requireThat(($report['historicalFloatSemantics'] ?? null) === $aggregate['historicalFloatSemantics'], 'Mixed float semantics: '.$name);
        $root = $report['root'] ?? '';
        requireThat(isset($summaries[$root]), 'Report omitted by aggregate: '.$name);
        $summary = $summaries[$root];
        requireThat(($summary['counts'] ?? null) === ($report['counts'] ?? null), 'Aggregate/report count mismatch: '.$name);
        requireThat(($summary['corpusIdentity'] ?? null) === ($report['corpusIdentity'] ?? null), 'Aggregate/report corpus mismatch: '.$name);
        requireThat(($summary['elapsedSeconds'] ?? null) === $report['elapsedSeconds'], 'Aggregate/report elapsed mismatch: '.$name);
        unset($summaries[$root]);
        requireThat(($classification['sourceReport'] ?? null) === $reportPath, 'Classification source report mismatch: '.$name);
        requireThat(($classification['shardGateSha256'] ?? null) === $gateSha256, 'Classification gate hash mismatch: '.$name);
        if (isset($classification['crossWorkerImport'])) {
            $import = $classification['crossWorkerImport'];
            $provenancePath = $campaign.'/provenance/'.$name;
            $validationPath = $campaign.'/validations/'.$name;
            $provenance = readJson($provenancePath);
            $validation = readJson($validationPath);
            $shard = pathinfo($name, PATHINFO_FILENAME);
            requireThat(($import['schema'] ?? null) === 'openpairing.cross-worker-import/1', 'Import schema mismatch: '.$name);
            requireThat(($import['primaryAcceptor'] ?? null) === 'DESKTOP-3OI0QHR', 'Primary acceptor mismatch: '.$name);
            requireThat(($import['reportSha256'] ?? null) === hash_file('sha256', $reportPath), 'Imported report hash mismatch: '.$name);
            requireThat(($import['attestationSha256'] ?? null) === hash_file('sha256', $provenancePath), 'Imported provenance hash mismatch: '.$name);
            requireThat(($import['validationSha256'] ?? null) === hash_file('sha256', $validationPath), 'Imported validation hash mismatch: '.$name);
            requireThat(($provenance['schema'] ?? null) === 'openpairing.cross-worker-provenance/1' && ($validation['schema'] ?? null) === 'openpairing.cross-worker-import-validation/1', 'Import metadata schema mismatch: '.$name);
            requireThat(($provenance['shard'] ?? null) === $shard && ($validation['shard'] ?? null) === $shard, 'Import shard mismatch: '.$name);
            requireThat(($provenance['host'] ?? null) === ($import['sourceHost'] ?? null) && ($validation['sourceHost'] ?? null) === $import['sourceHost'], 'Import source host mismatch: '.$name);
            requireThat(($provenance['campaignCommit'] ?? null) === '86ec9d9c003d68468906bddf12c69fbcaaf750ac' && ($validation['campaignCommit'] ?? null) === $provenance['campaignCommit'], 'Import commit mismatch: '.$name);
            requireThat(($provenance['reportSha256'] ?? null) === $import['reportSha256'] && ($validation['reportSha256'] ?? null) === $import['reportSha256'], 'Import report attestation mismatch: '.$name);
            requireThat(($validation['attestationSha256'] ?? null) === $import['attestationSha256'], 'Import provenance attestation mismatch: '.$name);
            requireThat(($provenance['canonicalSemanticSha256'] ?? null) === ($import['canonicalSemanticSha256'] ?? null) && ($validation['canonicalSemanticSha256'] ?? null) === $import['canonicalSemanticSha256'], 'Import semantic hash mismatch: '.$name);
            requireThat(($provenance['runtimeIdentity'] ?? null) === $report['runtimeIdentity'] && ($provenance['harnessIdentity'] ?? null) === $report['harnessIdentity'], 'Import runtime/harness provenance mismatch: '.$name);
            requireThat(($provenance['corpusIdentity'] ?? null) === $report['corpusIdentity'] && ($validation['corpusIdentity'] ?? null) === $report['corpusIdentity'], 'Import corpus provenance mismatch: '.$name);
            requireThat(($validation['sourceTreeSha256'] ?? null) === $aggregate['runtimeIdentity']['sourceTreeSha256'] && ($validation['harnessSha256'] ?? null) === $aggregate['harnessIdentity']['sha256'] && ($validation['arithmeticBackend'] ?? null) === 'gmp', 'Import validation identity mismatch: '.$name);
            requireThat(($validation['sentinel'] ?? null) === 'PASS_QUARANTINE_VALIDATED_NOT_YET_ACCEPTED', 'Import validation sentinel mismatch: '.$name);
            foreach (['filesSeen', 'tournamentsVerified', 'roundsAttempted'] as $field) {
                requireThat(($validation['counts'][$field] ?? null) === ($report['counts'][$field] ?? null), 'Import validation counter mismatch: '.$name.' '.$field);
            }
            $importedNames[] = $name;
            $host = $import['sourceHost'];
            $importedHosts[$host] = ($importedHosts[$host] ?? 0) + 1;
        } else {
            requireThat(($classification['campaignRunnerSha256'] ?? null) === $spec['runnerSha256'], 'Classification runner hash mismatch: '.$name);
            requireThat(!is_file($campaign.'/provenance/'.$name) && !is_file($campaign.'/validations/'.$name), 'Unrecorded import metadata: '.$name);
        }
        foreach (['sourceRuntimeIdentity' => 'runtimeIdentity', 'sourceHarnessIdentity' => 'harnessIdentity', 'sourceCorpusIdentity' => 'corpusIdentity'] as $source => $field) {
            if (isset($classification[$source])) {
                requireThat($classification[$source] === $report[$field], 'Classification source identity mismatch: '.$name);
            }
        }
        if (isset($classification['sourceHistoricalFloatSemantics'])) {
            requireThat($classification['sourceHistoricalFloatSemantics'] === $report['historicalFloatSemantics'], 'Classification float semantics mismatch: '.$name);
        }
        foreach ($totals as $key => $_) {
            requireThat(isset($report['counts'][$key]) && is_int($report['counts'][$key]), 'Missing report counter '.$key.': '.$name);
            $totals[$key] += $report['counts'][$key];
        }
        $required = ($report['counts']['roundsSamePairsColourVariant'] ?? 0) + ($report['counts']['roundsPairingDeviation'] ?? 0);
        requireThat(count($classification['results'] ?? []) === $required, 'Unclassified non-exact round: '.$name);
        $classified += $required;
        if ($required === 0) {
            requireThat(($classification['sentinel'] ?? null) === 'PASS_NO_ORACLE_CASES' && ($classification['classified'] ?? null) === 0, 'Missing zero-case PASS: '.$name);
            $verdicts['PASS_NO_ORACLE_CASES'] = ($verdicts['PASS_NO_ORACLE_CASES'] ?? 0) + 1;
        } else {
            $actualCategories = [];
            foreach ($classification['results'] as $result) {
                $category = $result['category'] ?? '';
                requireThat($category === 'checker-exact', 'Non-PASS Gacrux verdict: '.$name.' '.$category);
                requireThat(($result['checkerExact'] ?? null) === true && ($result['candidatePairs'] ?? null) === ($result['bestPairs'] ?? null), 'Checker-exact pair mismatch: '.$name);
                $actualCategories[$category] = ($actualCategories[$category] ?? 0) + 1;
            }
            requireThat($actualCategories === ($classification['counts'] ?? []), 'Classification category counter mismatch: '.$name);
            $verdicts['PASS_CHECKER_EXACT'] = ($verdicts['PASS_CHECKER_EXACT'] ?? 0) + 1;
        }
    }
    requireThat($summaries === [], 'Orphan aggregate report summaries');
    if ($partition === 'accel') {
        requireThat($importedNames === [], 'Acceleration must not contain cross-worker imports');
    }
    foreach (['provenance', 'validations'] as $directory) {
        if (is_dir($campaign.'/'.$directory)) {
            $names = array_values(array_diff(scandir($campaign.'/'.$directory) ?: [], ['.', '..']));
            sort($names);
            requireThat($names === $importedNames, 'Incomplete or orphan '.$directory.' basename set');
        } else {
            requireThat($importedNames === [], 'Missing '.$directory.' directory for imported shards');
        }
    }
    requireThat($totals === $aggregate['counts'], 'Partition aggregate counters do not reconcile');
    requireThat($totals['filesSeen'] === $spec['files'] && $totals['decoded'] === $spec['files'] && $totals['tournamentsVerified'] === $spec['files'], 'Tournament denominator mismatch');
    requireThat($totals['tournamentsAllExact'] === $spec['allExact'], 'Exact tournament denominator mismatch');
    requireThat($totals['roundsAttempted'] === $spec['rounds'] && $totals['roundsExact'] === $spec['exact'] && $totals['roundsPairingDeviation'] === $spec['deviations'], 'Round denominator mismatch');
    requireThat($totals['roundsSamePairsColourVariant'] === 0 && $totals['roundsEngineNoSolution'] === 0 && $totals['roundsEngineResourceLimit'] === 0, 'Engine/colour anomaly present');
    requireThat($classified === $spec['deviations'], 'Gacrux classification denominator mismatch');

    $stopExists = false;
    $nonEmptyErrors = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($campaign, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $basename = $file->getBasename();
        if (preg_match('/(^STOP$|\.stop$|stop\.json$)/i', $basename)) {
            $stopExists = true;
        }
        if (preg_match('/\.(err|error|stderr)$/i', $basename) && $file->getSize() > 0) {
            ++$nonEmptyErrors;
        }
    }
    requireThat(!$stopExists && $nonEmptyErrors === 0, 'STOP or nonempty error file in campaign');

    $receipt = [
        'schema' => $partition === 'accel'
            ? 'openpairing.acceleration-partition-promotion-receipt/1'
            : 'openpairing.historical-partition-promotion-receipt/1',
        'partition' => $partition,
        'epoch' => $spec['epoch'],
        'closedAt' => date('c', (int) filemtime($aggregatePath)),
        'verifiedAt' => date('c'),
        'campaignToolCommit' => $partition === 'accel'
            ? '5b0e7648663e92d7d08790fa6b899f01dd76a718'
            : '86ec9d9c003d68468906bddf12c69fbcaaf750ac',
        'aggregate' => [
            'repositoryPath' => $partition === 'accel'
                ? 'docs/evidence/accel-full-epoch5-virtual-float-20261003.json'
                : 'docs/evidence/'.$partition.'-full-86ec9d9-20261001.json',
            'sourcePath' => $aggregatePath,
            'bytes' => filesize($aggregatePath),
            'sha256' => hash_file('sha256', $aggregatePath),
        ],
        'identity' => [
            'engineSha256' => $aggregate['runtimeIdentity']['engineSha256'],
            'sourceTreeSha256' => $aggregate['runtimeIdentity']['sourceTreeSha256'],
            'harnessSha256' => $aggregate['harnessIdentity']['sha256'],
            'classificationGateSha256' => $gateSha256,
            'campaignRunnerSha256' => $spec['runnerSha256'],
            'historicalFloatSemantics' => $aggregate['historicalFloatSemantics'],
            'arithmeticBackend' => $aggregate['runtimeIdentity']['arithmeticBackend'],
            'runtimeRoots' => array_keys($runtimeRoots),
        ],
        'corpusIdentity' => [
            'fileCount' => $aggregate['corpusIdentity']['fileCount'],
            'totalBytes' => $aggregate['corpusIdentity']['totalBytes'],
            'shardManifestSha256' => $aggregate['corpusIdentity']['shardManifestSha256'],
        ],
        'counts' => ['shards' => $spec['shards'], 'reports' => $spec['shards'], 'classifications' => $spec['shards']] + $totals,
        'classification' => [
            'verdicts' => $verdicts,
            'requiredOracleCases' => $classified,
            'crossWorkerImports' => ['total' => count($importedNames), 'sourceHosts' => $importedHosts],
            'missingClassifications' => [],
            'orphanClassifications' => [],
            'sourceReportMismatches' => [],
        ],
        'health' => ['stopExists' => false, 'nonEmptyErrors' => 0],
        'sentinel' => 'PASS',
    ];
    $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    requireThat(file_put_contents($receiptPath, $json, LOCK_EX) !== false, 'Cannot write receipt');
    echo $partition.' PASS: '.$spec['shards'].' report/classification pairs; '.$spec['files'].' tournaments; '.$spec['rounds'].' rounds; '.$classified." Gacrux cases\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'PROMOTION_CHECK_FAIL: '.$error->getMessage()."\n");
    exit(1);
}
