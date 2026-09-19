# Build a distributable zip on Windows (excludes everything in .distignore).
$ErrorActionPreference = 'Stop'
$Root    = Split-Path -Parent $PSScriptRoot
$Slug    = 'ibg-client-outreach'
$Version = (Select-String -Path (Join-Path $Root "$Slug.php") -Pattern 'Version:\s*(\S+)' | Select-Object -First 1).Matches[0].Groups[1].Value
$Out     = Join-Path $Root 'dist'
$Stage   = Join-Path $Out $Slug
$Ignore  = Get-Content (Join-Path $Root '.distignore') | Where-Object { $_ -and -not $_.StartsWith('#') }

if (Test-Path $Stage) { Remove-Item -Recurse -Force $Stage }
New-Item -ItemType Directory -Force $Stage | Out-Null

Get-ChildItem -Path $Root -Force | Where-Object {
    $name = $_.Name
    -not ($Ignore | Where-Object { $name -like $_ }) -and $name -ne 'dist'
} | ForEach-Object { Copy-Item -Recurse -Force $_.FullName (Join-Path $Stage $_.Name) }

Get-ChildItem -Path $Stage -Recurse -Filter *.php | ForEach-Object {
    $r = & php -l $_.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Lint failed: $($_.FullName)`n$r" }
}

$Zip = Join-Path $Out "$Slug-$Version.zip"
if (Test-Path $Zip) { Remove-Item -Force $Zip }
Compress-Archive -Path $Stage -DestinationPath $Zip
Remove-Item -Recurse -Force $Stage
Write-Host "Built $Zip"
