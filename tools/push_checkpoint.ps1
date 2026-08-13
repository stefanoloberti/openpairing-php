[CmdletBinding()]
param(
    [string[]] $Remotes = @('origin', 'offsite')
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $root
try {
    if (& git status --porcelain=v1) {
        throw 'Checkpoint push requires a clean committed tree.'
    }
    $branch = (& git branch --show-current).Trim()
    if ($branch -eq '') {
        throw 'Checkpoint push requires a named branch.'
    }
    $branches = @($branch)
    if ($branch -ne 'main') {
        $mainHead = (& git rev-parse main 2>$null).Trim()
        if ($LASTEXITCODE -ne 0 -or $mainHead -ne (& git rev-parse HEAD).Trim()) {
            throw 'Non-main checkpoint pushes require local main at the same commit.'
        }
        $branches += 'main'
    }
    $head = (& git rev-parse HEAD).Trim()
    if ($head -notmatch '^[0-9a-f]{40}$') {
        throw 'Cannot resolve the local checkpoint commit.'
    }

    $results = foreach ($remote in $Remotes) {
        $url = & git remote get-url $remote 2>$null
        if ($LASTEXITCODE -ne 0 -or -not $url) {
            throw "Required backup remote is not configured: $remote"
        }
        & git push $remote @branches
        if ($LASTEXITCODE -ne 0) { throw "Branch push failed: $remote" }
        & git push $remote --tags
        if ($LASTEXITCODE -ne 0) { throw "Tag push failed: $remote" }
        $verifiedBranches = foreach ($pushedBranch in $branches) {
            $remoteLine = & git ls-remote $remote "refs/heads/$pushedBranch"
            if ($LASTEXITCODE -ne 0 -or -not $remoteLine) {
                throw "Cannot attest remote branch: $remote/$pushedBranch"
            }
            $remoteHead = ($remoteLine -split '\s+')[0]
            if ($remoteHead -ne $head) {
                throw "Remote branch does not match local HEAD: $remote/$pushedBranch"
            }
            [ordered]@{ branch = $pushedBranch; head = $remoteHead }
        }
        [ordered]@{
            remote = $remote
            url = $url.Trim()
            branches = @($verifiedBranches)
            verified = $true
        }
    }

    [ordered]@{
        sentinel = 'OPENPAIRING_CHECKPOINT_PUSH_PASS'
        branch = $branch
        head = $head
        remotes = @($results)
    } | ConvertTo-Json -Depth 5
} finally {
    Pop-Location
}
