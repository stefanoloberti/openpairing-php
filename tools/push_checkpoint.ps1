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
    $head = (& git rev-parse HEAD).Trim()
    if ($head -notmatch '^[0-9a-f]{40}$') {
        throw 'Cannot resolve the local checkpoint commit.'
    }

    $results = foreach ($remote in $Remotes) {
        $url = & git remote get-url $remote 2>$null
        if ($LASTEXITCODE -ne 0 -or -not $url) {
            throw "Required backup remote is not configured: $remote"
        }
        & git push $remote $branch
        if ($LASTEXITCODE -ne 0) { throw "Branch push failed: $remote/$branch" }
        & git push $remote --tags
        if ($LASTEXITCODE -ne 0) { throw "Tag push failed: $remote" }
        $remoteLine = & git ls-remote $remote "refs/heads/$branch"
        if ($LASTEXITCODE -ne 0 -or -not $remoteLine) {
            throw "Cannot attest remote branch: $remote/$branch"
        }
        $remoteHead = ($remoteLine -split '\s+')[0]
        if ($remoteHead -ne $head) {
            throw "Remote branch does not match local HEAD: $remote/$branch"
        }
        [ordered]@{
            remote = $remote
            url = $url.Trim()
            branch = $branch
            head = $remoteHead
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
