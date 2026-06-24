# -----------------------------------------------------------------------------
# run_dev.ps1 -- PowerShell mirror of run_dev.sh
#
# Loads secrets from .env.local (gitignored) and forwards them to `flutter`
# as --dart-define flags. Pass-through to any flutter subcommand:
#
#   .\run_dev.ps1                     # default: flutter run
#   .\run_dev.ps1 build apk --release
#   .\run_dev.ps1 test
#
# Mirrors run_dev.sh behaviour: fails fast if API_BASE is unset.
#
# Prereq: .env.local must exist one level up (mobile/.env.local).
# Requires PowerShell 5.1+ (default on Windows 10/11).
#
# NOTE: keep this file ASCII-only. PowerShell 5.1 can choke on non-ASCII
# characters (em-dashes, smart quotes) when they appear inside string
# expansions. Use -- or => for separators.
# -----------------------------------------------------------------------------

[CmdletBinding()]
param(
    # All positional args are forwarded to `flutter`. Example:
    #   .\run_dev.ps1 build apk
    # becomes: flutter --dart-define=... --dart-define=... build apk
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$FlutterArgs
)

$ErrorActionPreference = 'Stop'

# ---- Resolve paths -----------------------------------------------------------
$ScriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $ScriptDir
$EnvFile     = Join-Path $ProjectRoot '.env.local'

# ---- Load .env.local ---------------------------------------------------------
# Each non-comment, non-empty `KEY=VALUE` line becomes a process-scope env var.
# Mirrors the bash `set -a; source; set +a` trick.
if (Test-Path -LiteralPath $EnvFile) {
    Get-Content -LiteralPath $EnvFile | ForEach-Object {
        $line = $_.Trim()
        if ($line -eq '') { return }
        if ($line.StartsWith('#')) { return }
        # Split on first '=' only (values may contain '=' themselves, e.g. URLs)
        $eq = $line.IndexOf('=')
        if ($eq -lt 1) { return }
        $key = $line.Substring(0, $eq).Trim()
        $val = $line.Substring($eq + 1).Trim()
        # Strip optional surrounding quotes
        if ($val.Length -ge 2) {
            $first = $val[0]
            $last  = $val[$val.Length - 1]
            if (($first -eq '"' -and $last -eq '"') -or
                ($first -eq "'" -and $last -eq "'")) {
                $val = $val.Substring(1, $val.Length - 2)
            }
        }
        [Environment]::SetEnvironmentVariable($key, $val, 'Process')
    }
}
else {
    Write-Host '[run_dev] .env.local not found -- falling back to existing env.'
    Write-Host '[run_dev] Copy .env.example to .env.local to set defaults.'
}

# ---- Build --dart-define flags ----------------------------------------------
# Same allowlist as run_dev.sh. Add new keys here when needed.
$DartDefines = New-Object System.Collections.Generic.List[string]
foreach ($key in @('GOOGLE_CLIENT_ID', 'API_BASE')) {
    $val = [Environment]::GetEnvironmentVariable($key, 'Process')
    if (-not [string]::IsNullOrEmpty($val)) {
        $DartDefines.Add("--dart-define=$key=$val")
    }
}

# ---- Fail-fast on missing API_BASE ------------------------------------------
$apiBase = [Environment]::GetEnvironmentVariable('API_BASE', 'Process')
if ([string]::IsNullOrEmpty($apiBase)) {
    Write-Host '[run_dev] API_BASE is not set in .env.local.'
    Write-Host '[run_dev] Add a line like:  API_BASE=http://10.0.2.2:8080/api/v1'
    Write-Host '[run_dev]   - Android emulator -> host PC : http://10.0.2.2:8080/api/v1'
    Write-Host '[run_dev]   - iOS simulator                : http://localhost:8080/api/v1'
    Write-Host '[run_dev]   - Physical device on LAN       : http://<your-LAN-IP>:8080/api/v1'
    Write-Host '[run_dev]   - Production                   : https://api.example.com/api/v1'
    exit 1
}

# ---- Invoke flutter ----------------------------------------------------------
Set-Location -LiteralPath $ProjectRoot

# Build the log line without nested $(...) in a double-quoted string --
# PowerShell 5.1's parser can drop the closing quote otherwise.
$definesStr = $DartDefines -join ' '
$argsStr    = if ($FlutterArgs) { $FlutterArgs -join ' ' } else { '' }
$logCmd     = 'flutter ' + $definesStr + ' ' + $argsStr
Write-Host ('[run_dev] cd ' + $ProjectRoot)
Write-Host ('[run_dev] API_BASE = ' + $apiBase)
Write-Host ('[run_dev] ' + $logCmd.Trim())

& flutter run @DartDefines @FlutterArgs
exit $LASTEXITCODE
