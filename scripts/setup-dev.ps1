#Requires -Version 5.1
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))

function New-DevSecret {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return ([System.BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
}

$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    $content = [System.IO.File]::ReadAllText((Join-Path $projectRoot '.env.example'))
    $content = [regex]::Replace($content, '(?m)^DB_PASSWORD=\r?$', ('DB_PASSWORD=' + (New-DevSecret)))
    $content = [regex]::Replace($content, '(?m)^MARIADB_ROOT_PASSWORD=\r?$', ('MARIADB_ROOT_PASSWORD=' + (New-DevSecret)))
    [System.IO.File]::WriteAllText($envPath, $content, (New-Object System.Text.UTF8Encoding($false)))
    Write-Output 'Created .env with random DEV database passwords. Existing credentials were not changed.'
} else {
    Write-Output 'Using existing .env.'
}

$testPath = Join-Path $projectRoot '.env.testing'
if (-not (Test-Path -LiteralPath $testPath)) {
    $content = [System.IO.File]::ReadAllText($envPath)
    $content = $content.Replace('APP_ENV=local', 'APP_ENV=testing')
    $content = $content.Replace('DB_DATABASE=smh_learning_dev', 'DB_DATABASE=smh_learning_test')
    $content = $content.Replace('REDIS_PREFIX=smh_learning_dev_', 'REDIS_PREFIX=smh_learning_test_')
    $content = $content.Replace('SESSION_DRIVER=redis', 'SESSION_DRIVER=array')
    $content = $content.Replace('QUEUE_CONNECTION=redis', 'QUEUE_CONNECTION=sync')
    $content = $content.Replace('MAIL_MAILER=log', 'MAIL_MAILER=array')
    [System.IO.File]::WriteAllText($testPath, $content, (New-Object System.Text.UTF8Encoding($false)))
    Write-Output 'Created .env.testing using the separate test database and Redis prefix.'
}
