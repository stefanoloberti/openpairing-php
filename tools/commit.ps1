[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateNotNullOrEmpty()]
    [string] $Message,

    [switch] $Wip
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$php = Join-Path $repositoryRoot 'tools\runtime\php\php.exe'
$composer = Join-Path $repositoryRoot 'tools\runtime\composer.phar'

Push-Location $repositoryRoot
try {
    & git.exe rev-parse --is-inside-work-tree | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'The project is not a Git worktree.'
    }

    & git.exe diff --quiet
    if ($LASTEXITCODE -ne 0) {
        throw 'Unstaged tracked changes exist. Stage the intended checkpoint first.'
    }

    $untracked = @(& git.exe ls-files --others --exclude-standard)
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not inspect untracked files.'
    }
    if ($untracked.Count -ne 0) {
        throw ('Untracked files exist: ' + ($untracked -join ', '))
    }

    & git.exe diff --cached --quiet
    if ($LASTEXITCODE -eq 0) {
        throw 'There are no staged changes to commit.'
    }
    if ($LASTEXITCODE -ne 1) {
        throw 'Could not inspect staged changes.'
    }

    & $php $composer check
    if ($LASTEXITCODE -ne 0) {
        throw "composer check failed with exit code $LASTEXITCODE; commit refused."
    }

    & git.exe diff --quiet
    if ($LASTEXITCODE -ne 0) {
        throw 'The rapid gate changed tracked files; commit refused.'
    }

    $commitMessage = if ($Wip) { 'WIP: ' + $Message } else { $Message }
    & git.exe commit -m $commitMessage
    if ($LASTEXITCODE -ne 0) {
        throw "git commit failed with exit code $LASTEXITCODE."
    }
}
finally {
    Pop-Location
}
