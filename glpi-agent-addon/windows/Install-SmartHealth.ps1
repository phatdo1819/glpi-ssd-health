# SPDX-License-Identifier: MIT

<#
.SYNOPSIS
    Adds SSD health reporting to an installed GLPI Agent on Windows.

.DESCRIPTION
    Copies the SSD health module (SmartHealth.pm), smartctl.exe, its drive database and the
    smartmontools license into the GLPI Agent folder. The custom agent installer contains the
    same files, so the result is the same, but the agent itself stays the official, signed one.

    Files already up to date are left alone, so the script can run at every startup through
    Group Policy. Keep it in the add-on's windows folder: it takes SmartHealth.pm from the
    folder above and the other files from its own folder.

    Must run as Administrator or SYSTEM. The agent uses the module from its next inventory,
    without a restart.

.PARAMETER Uninstall
    Removes the files this script adds. Disks are then reported without SMART data.

.PARAMETER SkipCheck
    Skips the quick inventory that shows the SMART data found on each disk. Use it at startup.

.PARAMETER LogPath
    Also writes the output to this file, which helps when the script runs at startup.

.EXAMPLE
    .\Install-SmartHealth.ps1

.EXAMPLE
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File \\fileserver\SsdHealth$\glpi-agent-addon\windows\Install-SmartHealth.ps1 -SkipCheck -LogPath C:\Windows\Temp\SmartHealth.log
#>
[CmdletBinding()]
param(
    [switch] $Uninstall,
    [switch] $SkipCheck,
    [string] $LogPath
)

$ErrorActionPreference = 'Stop'

function Test-Elevated {
    $principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-AgentFolder {
    # The agent installer records its folder in the 64-bit registry, also read from 32-bit PowerShell
    $hklm = [Microsoft.Win32.RegistryKey]::OpenBaseKey(
        [Microsoft.Win32.RegistryHive]::LocalMachine, [Microsoft.Win32.RegistryView]::Registry64)
    $key = $hklm.OpenSubKey('SOFTWARE\GLPI-Agent\Installer')
    if ($key) {
        $folder = $key.GetValue('InstallDir')
        $key.Close()
        if ($folder) { return $folder.TrimEnd('\') }
    }
    return Join-Path $env:ProgramW6432 'GLPI-Agent'
}

function Get-AgentVersion([string] $AgentFolder) {
    $match = Select-String -LiteralPath (Join-Path $AgentFolder 'perl\agent\GLPI\Agent\Version.pm') `
        -Pattern 'VERSION\s*=\s*"([^"]+)"' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($match) { return $match.Matches[0].Groups[1].Value }
    return 'unknown version'
}

function Get-AddonFiles {
    # Source and destination, relative to the agent folder, of each file
    $module = Join-Path (Split-Path -Parent $PSScriptRoot) 'SmartHealth.pm'
    if (-not (Test-Path -LiteralPath $module)) { $module = Join-Path $PSScriptRoot 'SmartHealth.pm' }

    return @(
        @{ Source = $module; Target = 'perl\agent\GLPI\Agent\Task\Inventory\Generic\Storages\SmartHealth.pm' }
        @{ Source = (Join-Path $PSScriptRoot 'smartctl.exe'); Target = 'perl\bin\smartctl.exe' }
        @{ Source = (Join-Path $PSScriptRoot 'drivedb.h'); Target = 'perl\bin\drivedb.h' }
        @{ Source = (Join-Path $PSScriptRoot 'smartmontools-COPYING.txt'); Target = 'perl\bin\smartmontools-COPYING.txt' }
    )
}

function Copy-AddonFile([string] $Source, [string] $Destination) {
    if ((Test-Path -LiteralPath $Destination) -and
        (Get-FileHash -LiteralPath $Source).Hash -eq (Get-FileHash -LiteralPath $Destination).Hash) {
        return 'up to date'
    }

    # smartctl.exe is in use while the agent runs an inventory
    for ($attempt = 1; ; $attempt++) {
        try {
            Copy-Item -LiteralPath $Source -Destination $Destination -Force
            return 'copied'
        } catch {
            if ($attempt -ge 6) { throw }
            Start-Sleep -Seconds 10
        }
    }
}

function Show-SmartData([string] $AgentFolder) {
    # Read the JSON from a file, as UTF-8: drive labels can have accented letters
    $output = Join-Path $env:TEMP "smarthealth-check-$PID.json"
    try {
        & cmd.exe /c "`"$(Join-Path $AgentFolder 'glpi-inventory.bat')`" --partial storage,storage_health --json > `"$output`" 2>nul"
        $inventory = Get-Content -LiteralPath $output -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        Write-Warning "Check skipped: the quick inventory failed ($($_.Exception.Message))"
        return
    } finally {
        Remove-Item -LiteralPath $output -Force -ErrorAction SilentlyContinue
    }

    $disks = @($inventory.content.storages | Where-Object { $null -ne $_.smart_health -or $_.smart_status })
    if (-not $disks.Count) {
        'Check: no SMART data found. Virtual machine disks and some USB or RAID disks report none.'
        return
    }

    'Check: SMART data found:'
    foreach ($disk in $disks) {
        $health = if ($null -ne $disk.smart_health) { "$($disk.smart_health)%" } else { 'no wear counter' }
        '  {0} {1}: health {2}, SMART {3}, volumes {4}' -f $disk.name, $disk.model, $health, $disk.smart_status, $disk.smart_volumes
    }
    'It reaches GLPI with the next inventory, or now from http://127.0.0.1:62354/now if the agent runs as a service.'
}

function Invoke-Setup {
    if (-not (Test-Elevated)) { throw 'Run this script as Administrator or SYSTEM.' }
    if (-not [Environment]::Is64BitOperatingSystem) { throw 'GLPI Agent with SSD health needs 64-bit Windows.' }

    $agent = Get-AgentFolder
    $files = Get-AddonFiles
    $present = @($files | Where-Object { Test-Path -LiteralPath (Join-Path $agent $_.Target) })

    if (-not (Test-Path -LiteralPath (Join-Path $agent 'perl\bin\glpi-agent.exe'))) {
        if (-not $Uninstall) { throw "GLPI Agent not found in $agent. Install the official agent first." }

        # Uninstalling the agent leaves the files this script added: remove them and the folders left empty
        foreach ($file in $present) {
            Remove-Item -LiteralPath (Join-Path $agent $file.Target) -Force
            "$($file.Target): removed"
        }
        if (Test-Path -LiteralPath $agent) {
            Get-ChildItem -LiteralPath $agent -Directory -Recurse |
                Sort-Object { $_.FullName.Length } -Descending |
                Where-Object { -not (Get-ChildItem -LiteralPath $_.FullName -Force) } |
                Remove-Item -Force
            if (-not (Get-ChildItem -LiteralPath $agent -Force)) { Remove-Item -LiteralPath $agent -Force }
        }
        'GLPI Agent is not installed: SSD health files left behind are removed.'
        return
    }

    $version = Get-AgentVersion $agent
    "GLPI Agent $version in $agent"

    # Custom installer builds are versioned like 1.20-git05c29b27 and include these files
    if ($version -match '-git' -and $present.Count -eq $files.Count) {
        'This PC runs the custom agent installer, which already includes SSD health. Nothing to do.'
        if ($Uninstall) { 'To remove SSD health, install the official agent over it.' }
        return
    }

    if ($Uninstall) {
        foreach ($file in $present) {
            Remove-Item -LiteralPath (Join-Path $agent $file.Target) -Force
            "$($file.Target): removed"
        }
        'SSD health removed. Disks are reported without SMART data from the next inventory.'
        return
    }

    foreach ($file in $files) {
        if (-not (Test-Path -LiteralPath $file.Source)) {
            throw "$($file.Source) not found. Keep this script in the add-on's windows folder."
        }
        $state = Copy-AddonFile -Source $file.Source -Destination (Join-Path $agent $file.Target)
        "$($file.Target): $state"
    }
    'SSD health installed. The agent uses it from its next inventory, without a restart.'

    if (-not $SkipCheck) { Show-SmartData $agent }
}

$exitCode = 0
if ($LogPath) { Start-Transcript -LiteralPath $LogPath -Append | Out-Null }
try {
    Invoke-Setup
} catch {
    Write-Error -Message $_.Exception.Message -ErrorAction Continue
    $exitCode = 1
} finally {
    if ($LogPath) { Stop-Transcript | Out-Null }
}
exit $exitCode
