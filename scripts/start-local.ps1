param(
    [switch]$Build,
    [switch]$InitializeDatabase,
    [string]$BuildProxy = ''
)

$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path $PSScriptRoot -Parent
$dockerDirectory = Join-Path $env:ProgramFiles 'Docker/Docker/resources/bin'
if (Test-Path -LiteralPath $dockerDirectory) {
    $env:Path = "$dockerDirectory;$env:SystemRoot/System32;$env:Path"
}

function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) {
        throw "Docker 命令执行失败，退出码：$LASTEXITCODE"
    }
}

Push-Location $projectDirectory
try {
    & docker info --format '{{.ServerVersion}}' 2>$null
    if ($LASTEXITCODE -ne 0) {
        Invoke-Docker desktop start
    }

    if (-not (Test-Path -LiteralPath '.env' -PathType Leaf)) {
        throw '先复制 .env.example 为根目录 .env 并填写本地配置。'
    }
    if (-not (Select-String -LiteralPath '.env' -Pattern '^\s*APP_ENV\s*=\s*["'']?local["'']?\s*(#.*)?$' -Quiet)) {
        throw '此入口仅供 APP_ENV=local；生产请使用发布流程。'
    }
    $composeArguments = @('compose', '-f', 'docker-compose.yml')
    & docker image inspect saveb-api-app:local --format '{{.Id}}' 2>$null
    $imageMissing = $LASTEXITCODE -ne 0
    if ($Build -or $imageMissing) {
        $buildArguments = $composeArguments + @('build')
        if ($BuildProxy) {
            $buildArguments += @('--build-arg', "HTTP_PROXY=$BuildProxy", '--build-arg', "HTTPS_PROXY=$BuildProxy")
        }
        Invoke-Docker @buildArguments app
    }
    Invoke-Docker @composeArguments up -d --no-build
    if ($InitializeDatabase) {
        # 命令内部检查 APP_ENV 和空库，已有数据时拒绝执行。
        Invoke-Docker exec saveb-api-app php artisan local:database-init
    }
    Invoke-Docker @composeArguments ps
}
finally {
    Pop-Location
}
