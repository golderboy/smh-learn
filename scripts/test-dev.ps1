#Requires -Version 5.1
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
Push-Location $projectRoot
try {
    $steps = @(
        @{ Command = 'docker'; Arguments = @('compose', 'config', '--quiet') },
        @{ Command = 'docker'; Arguments = @('compose', 'exec', '-T', 'nginx', 'nginx', '-t') },
        @{ Command = 'docker'; Arguments = @('compose', 'exec', '-T', 'app', 'composer', 'validate', '--strict') },
        @{ Command = 'docker'; Arguments = @('compose', 'exec', '-T', 'app', 'composer', 'check-platform-reqs') },
        @{ Command = 'docker'; Arguments = @('compose', 'exec', '-T', 'app', 'composer', 'lint') },
        @{ Command = 'docker'; Arguments = @('compose', 'exec', '-T', 'app', 'composer', 'analyse') },
        @{ Command = 'npm.cmd'; Arguments = @('run', 'lint') },
        @{ Command = 'npm.cmd'; Arguments = @('run', 'format:check') },
        @{ Command = 'npm.cmd'; Arguments = @('run', 'build') },
        @{ Command = 'docker'; Arguments = @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'test') },
        @{ Command = 'npm.cmd'; Arguments = @('run', 'test:unit') },
        @{ Command = 'docker'; Arguments = @('compose', 'exec', '-T', 'app', 'php', 'artisan', 'dev:check', '--queue', '--scheduler') },
        @{ Command = 'npm.cmd'; Arguments = @('run', 'test:e2e') }
    )
    foreach ($step in $steps) {
        Write-Output ('Running: ' + $step.Command + ' ' + ($step.Arguments -join ' '))
        $stepArgs = $step.Arguments
        & $step.Command @stepArgs
        if ($LASTEXITCODE -ne 0) { throw ('Check failed: ' + $step.Command + ' ' + ($stepArgs -join ' ')) }
    }
    Write-Output 'All DEV environment checks passed.'
} finally {
    Pop-Location
}
