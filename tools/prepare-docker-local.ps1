$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$templatePath = Join-Path $projectRoot '.env.docker.local.example'
$destinationPath = Join-Path $projectRoot '.env.docker'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker Desktop is not installed or its docker command is not available. Install and start Docker Desktop, then run this task again.'
}

if (-not (Test-Path -LiteralPath $destinationPath)) {
    $bytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
    $appKey = 'base64:' + [Convert]::ToBase64String($bytes)
    $databasePassword = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)).Replace('/','A').Replace('+','B').TrimEnd('=')
    $rootPassword = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(28)).Replace('/','C').Replace('+','D').TrimEnd('=')
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
