[CmdletBinding()]
param(
    [string]$OutputPath = (Join-Path (Split-Path $PSScriptRoot -Parent) "docs\README.pdf")
)

$ErrorActionPreference = "Stop"
$projectRoot = Split-Path $PSScriptRoot -Parent
$readmePath = Join-Path $projectRoot "README.md"
$stylesheetPath = Join-Path $projectRoot "docs\readme-pdf.css"

if (-not (Test-Path -LiteralPath $readmePath)) {
    throw "No se encontro el README: $readmePath"
}
if (-not (Test-Path -LiteralPath $stylesheetPath)) {
    throw "No se encontro la hoja de estilos: $stylesheetPath"
}

$pandocCommand = Get-Command pandoc -ErrorAction SilentlyContinue
if ($null -ne $pandocCommand) {
    $pandocPath = $pandocCommand.Source
} else {
    $localPandoc = Join-Path $env:LOCALAPPDATA "Pandoc\pandoc.exe"
    if (Test-Path -LiteralPath $localPandoc) {
        $pandocPath = $localPandoc
    } else {
        throw "Pandoc no esta instalado o no esta disponible. Instalalo y vuelve a abrir PowerShell."
    }
}

$braveCandidates = @(
    (Join-Path $env:LOCALAPPDATA "BraveSoftware\Brave-Browser\Application\brave.exe"),
    (Join-Path $env:ProgramFiles "BraveSoftware\Brave-Browser\Application\brave.exe"),
    (Join-Path ${env:ProgramFiles(x86)} "BraveSoftware\Brave-Browser\Application\brave.exe")
) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }
$braveCandidates = @($braveCandidates)

$rendererCandidates = @(
    (Join-Path $env:ProgramFiles "Microsoft\Edge\Application\msedge.exe"),
    (Join-Path ${env:ProgramFiles(x86)} "Microsoft\Edge\Application\msedge.exe"),
    (Join-Path $env:ProgramFiles "Google\Chrome\Application\chrome.exe"),
    (Join-Path ${env:ProgramFiles(x86)} "Google\Chrome\Application\chrome.exe")
) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }
$rendererCandidates = @($rendererCandidates)

if (-not $braveCandidates) {
    throw "No se encontro Brave para abrir el PDF generado."
}
if (-not $rendererCandidates) {
    throw "No se encontro Microsoft Edge ni Google Chrome para exportar el PDF."
}
$bravePath = $braveCandidates[0]
$rendererPath = $rendererCandidates[0]

$outputFullPath = [System.IO.Path]::GetFullPath($OutputPath)
$outputDirectory = Split-Path $outputFullPath -Parent
New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
$htmlPath = Join-Path $outputDirectory (".readme-print-{0}.html" -f [guid]::NewGuid().ToString("N"))
$workingPdfPath = Join-Path $outputDirectory (".readme-print-{0}.pdf" -f [guid]::NewGuid().ToString("N"))
$browserProfile = Join-Path $env:TEMP ("fct-readme-pdf-{0}" -f [guid]::NewGuid().ToString("N"))

try {
    $stylesheetUri = ([System.Uri]$stylesheetPath).AbsoluteUri
    $pandocArguments = @(
        $readmePath,
        "--standalone",
        "--from=gfm",
        "--css=$stylesheetUri",
        "--metadata=title:",
        "--output=$htmlPath"
    )
    & $pandocPath @pandocArguments
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $htmlPath)) {
        throw "Pandoc no pudo generar el HTML de impresion (codigo $LASTEXITCODE)."
    }

    $htmlUri = ([System.Uri]$htmlPath).AbsoluteUri
    & $rendererPath `
        "--headless=new" `
        --disable-gpu `
        --no-first-run `
        --no-pdf-header-footer `
        "--user-data-dir=$browserProfile" `
        "--print-to-pdf=$workingPdfPath" `
        $htmlUri

    $browserExitCode = $LASTEXITCODE
    for ($attempt = 0; $attempt -lt 60 -and -not (Test-Path -LiteralPath $workingPdfPath); $attempt++) {
        Start-Sleep -Milliseconds 500
    }
    $pdfExists = Test-Path -LiteralPath $workingPdfPath
    $pdfSize = if ($pdfExists) { (Get-Item -LiteralPath $workingPdfPath).Length } else { 0 }
    if ($browserExitCode -ne 0 -or -not $pdfExists -or $pdfSize -lt 1000) {
        throw "El navegador no pudo generar el PDF (codigo $browserExitCode)."
    }

    Move-Item -LiteralPath $workingPdfPath -Destination $outputFullPath -Force
    Start-Process -FilePath $bravePath -ArgumentList ('"{0}"' -f $outputFullPath)
    Write-Output "PDF generado: $outputFullPath"
} finally {
    Remove-Item -LiteralPath $htmlPath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $workingPdfPath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $browserProfile -Recurse -Force -ErrorAction SilentlyContinue
}
