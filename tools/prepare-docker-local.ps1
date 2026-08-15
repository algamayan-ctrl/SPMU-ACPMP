$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$templatePath = Join-Path $projectRoot '.env.docker.local.example'
$destinationPath = Join-Path $projectRoot '.env.docker'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker Desktop is not installed or its docker command is not available. Install and start Docker Desktop, then run this task again.'
}

if (-not (Test-Path -LiteralPath $destinationPath)) {
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()

    $bytes = New-Object byte[] 32
    $rng.GetBytes($bytes)
    $appKey = 'base64:' + [Convert]::ToBase64String($bytes)

    $dbBytes = New-Object byte[] 24
    $rng.GetBytes($dbBytes)
    $databasePassword = [Convert]::ToBase64String($dbBytes).Replace('/','A').Replace('+','B').TrimEnd('=')

    $rootBytes = New-Object byte[] 28
    $rng.GetBytes($rootBytes)
    $rootPassword = [Convert]::ToBase64String($rootBytes).Replace('/','C').Replace('+','D').TrimEnd('=')

    $rng.Dispose()

    $content = Get-Content -LiteralPath $templatePath -Raw
    $content = $content.Replace('base64:REPLACE_WITH_GENERATED_KEY', $appKey)
    $content = $content.Replace('REPLACE_WITH_GENERATED_DATABASE_PASSWORD', $databasePassword)
    $content = $content.Replace('REPLACE_WITH_GENERATED_ROOT_PASSWORD', $rootPassword)
    [IO.File]::WriteAllText($destinationPath, $content, [Text.UTF8Encoding]::new($false))
    Write-Host 'Created .env.docker with generated local-only secrets.' -ForegroundColor Green
} else {
    Write-Host '.env.docker already exists and was left unchanged.' -ForegroundColor Yellow
}

docker version | Out-Null
Write-Host 'Docker is ready. Run the VS Code task: SPMU: Start Docker + MariaDB' -ForegroundColor Green