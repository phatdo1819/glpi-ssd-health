# SPDX-License-Identifier: GPL-3.0-or-later

<#
.SYNOPSIS
    Reports SSD health (% of rated life left, like CrystalDiskInfo) for every disk in this PC.

.DESCRIPTION
    Uses smartctl (smartmontools 7.5 or later) to read each disk's SMART data and turns it
    into one row per disk: health %, where that value came from, a replacement status and
    any problems found.

    Meant to run as SYSTEM from a Group Policy scheduled task. With -ReportPath it writes
    <ReportPath>\<COMPUTERNAME>.csv, overwritten on every run; combine all PCs with
    Merge-SsdHealthReports.ps1. Without -ReportPath it only prints the rows.

    Must run elevated (Administrator or SYSTEM); smartctl cannot open disks otherwise.

    Status values, worst first:
      REPLACE NOW   SMART failed, NVMe critical warning, failing attribute, or health <= CritPercent
      REPLACE SOON  health <= WarnPercent, pending/uncorrectable sectors, or NVMe media errors
      ERROR         smartctl could not read the disk (see Problems)
      UNKNOWN       SSD that reports no wear counter; check it with the vendor tool
      OK            nothing wrong found
      VIRTUAL       virtual machine disk, health is meaningless

.PARAMETER ReportPath
    Folder to write <COMPUTERNAME>.csv to, usually a UNC share.

.PARAMETER SmartctlPath
    Full path to smartctl.exe. By default the script uses smartctl.exe in its own folder,
    then the smartmontools install folder, then PATH.

.PARAMETER WarnPercent
    Health at or below this is REPLACE SOON. Default 30.

.PARAMETER CritPercent
    Health at or below this is REPLACE NOW. Default 10.

.EXAMPLE
    .\Get-SsdHealth.ps1 | Format-Table Disk, Model, HealthPercent, Status, Problems

.EXAMPLE
    .\Get-SsdHealth.ps1 -ReportPath \\fileserver\SsdHealth$\reports
#>
[CmdletBinding()]
param(
    [string] $ReportPath,
    [string] $SmartctlPath,
    [ValidateRange(0, 100)] [int] $WarnPercent = 30,
    [ValidateRange(0, 100)] [int] $CritPercent = 10
)

# smartctl exit status bits (smartctl man page, RETURN VALUES)
$EXIT_CMDLINE_ERROR = 0x01
$EXIT_OPEN_FAILED   = 0x02
$EXIT_SMART_FAILING = 0x08

# ATA attributes whose normalized value counts down from 100 as the SSD wears out, as named
# by smartctl's drive database. Checked in this order: explicit life counters first.
$LifeAttributePatterns = @(
    '(?i)life.*(left|remain)|remain.*life',
    '(?i)^Media_Wearout_Indicator$',
    '(?i)^Wear_Leveling_Count$'
)

$VirtualModelPattern = '(?i)virtual|vmware|vbox|qemu'

function Test-Elevated {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    (New-Object Security.Principal.WindowsPrincipal $identity).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Find-Smartctl {
    param([string] $Path)

    if ($Path) {
        if (Test-Path -LiteralPath $Path -PathType Leaf) { return $Path }
        throw "smartctl.exe not found at '$Path'"
    }
    $candidates = @(
        (Join-Path $PSScriptRoot 'smartctl.exe'),
        (Join-Path $env:ProgramFiles 'smartmontools\bin\smartctl.exe')
    )
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
    }
    $command = Get-Command smartctl.exe -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    throw 'smartctl.exe not found next to this script, in the smartmontools install folder or on PATH'
}

function Invoke-Smartctl {
    param(
        [Parameter(Mandatory)] [string] $Smartctl,
        [Parameter(Mandatory)] [string[]] $Arguments,
        [int] $TimeoutSeconds = 120
    )

    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $Smartctl
    $startInfo.Arguments = $Arguments -join ' '
    $startInfo.UseShellExecute = $false
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $startInfo.CreateNoWindow = $true

    $process = [System.Diagnostics.Process]::Start($startInfo)
    $stdout = $process.StandardOutput.ReadToEndAsync()
    $stderr = $process.StandardError.ReadToEndAsync()
    if (-not $process.WaitForExit($TimeoutSeconds * 1000)) {
        $process.Kill()
        throw "smartctl $($startInfo.Arguments) timed out after $TimeoutSeconds seconds"
    }

    $data = $null
    try { $data = $stdout.Result | ConvertFrom-Json } catch { }
    if ($null -eq $data) {
        $output = ($stdout.Result + ' ' + $stderr.Result).Trim()
        throw "smartctl $($startInfo.Arguments) returned no JSON (exit code $($process.ExitCode)): $output"
    }
    return $data
}

function New-HealthRow {
    param([string] $Disk, [string] $Status, [string] $Problems)

    [pscustomobject][ordered]@{
        ComputerName    = $env:COMPUTERNAME
        Disk            = $Disk
        Model           = $null
        Serial          = $null
        Firmware        = $null
        Protocol        = $null
        MediaType       = $null
        CapacityGB      = $null
        HealthPercent   = $null
        HealthSource    = $null
        Status          = $Status
        Problems        = $Problems
        SmartPassed     = $null
        PowerOnHours    = $null
        WrittenTB       = $null
        TemperatureC    = $null
        ReportTime      = (Get-Date).ToString('yyyy-MM-dd HH:mm')
        SmartctlVersion = $null
    }
}

function Get-DeviceStatistic {
    param($Data, [int] $Page, [int] $Offset)

    foreach ($statsPage in $Data.ata_device_statistics.pages) {
        if ($statsPage.number -ne $Page) { continue }
        foreach ($entry in $statsPage.table) {
            if ($entry.offset -eq $Offset -and $entry.flags.valid -and $null -ne $entry.value) {
                return $entry.value
            }
        }
    }
    return $null
}

function Get-HealthPercent {
    param($Data)

    $used = $Data.nvme_smart_health_information_log.percentage_used
    if ($null -ne $used) {
        return @{ Percent = [Math]::Max(0, 100 - [int]$used); Source = 'NVMe Percentage Used' }
    }

    # ATA Device Statistics page 7 "Percentage Used Endurance Indicator" (ACS-3 standard)
    $used = Get-DeviceStatistic -Data $Data -Page 7 -Offset 8
    if ($null -ne $used) {
        return @{ Percent = [Math]::Max(0, 100 - [int]$used); Source = 'Device Statistics (Percentage Used)' }
    }

    # Vendor-specific attributes. Normalized values must be 1-100 to be a percentage.
    foreach ($pattern in $LifeAttributePatterns) {
        foreach ($attribute in $Data.ata_smart_attributes.table) {
            if ($attribute.id -ge 100 -and $attribute.name -match $pattern -and
                $attribute.value -ge 1 -and $attribute.value -le 100) {
                return @{ Percent = [int]$attribute.value; Source = "Attribute $($attribute.id) $($attribute.name)" }
            }
        }
    }

    # Whatever else smartctl 7.5+ derived itself, e.g. SAS SSDs
    $used = $Data.endurance_used.current_percent
    if ($null -ne $used) {
        return @{ Percent = [Math]::Max(0, 100 - [int]$used); Source = 'smartctl endurance_used' }
    }
    return $null
}

function Get-WrittenTB {
    param($Data)

    # NVMe data units are 1000 x 512 bytes
    $units = $Data.nvme_smart_health_information_log.data_units_written
    if ($null -ne $units) { return [Math]::Round([double]$units * 512000 / 1e12, 1) }

    # ATA Device Statistics page 1 "Logical Sectors Written"
    $sectors = Get-DeviceStatistic -Data $Data -Page 1 -Offset 0x18
    if ($null -ne $sectors) {
        $blockSize = $Data.logical_block_size
        if (-not $blockSize) { $blockSize = 512 }
        return [Math]::Round([double]$sectors * $blockSize / 1e12, 1)
    }
    return $null
}

function ConvertTo-DiskHealth {
    param(
        [Parameter(Mandatory)] $Data,
        [Parameter(Mandatory)] [string] $Disk,
        [int] $WarnPercent = 30,
        [int] $CritPercent = 10
    )

    $row = New-HealthRow -Disk $Disk
    $row.SmartctlVersion = $Data.smartctl.version -join '.'
    $exitStatus = [int]$Data.smartctl.exit_status
    $messages = @($Data.smartctl.messages | ForEach-Object { $_.string })

    if (($exitStatus -band ($EXIT_CMDLINE_ERROR -bor $EXIT_OPEN_FAILED)) -or
        ($null -eq $Data.model_name -and $null -eq $Data.smart_status)) {
        $row.Status = 'ERROR'
        $row.Problems = (@('smartctl could not read this disk') + $messages) -join '; '
        return $row
    }

    $nvme = $Data.nvme_smart_health_information_log
    $row.Model = $Data.model_name
    $row.Serial = $Data.serial_number
    $row.Firmware = $Data.firmware_version
    $row.Protocol = $Data.device.protocol
    $row.SmartPassed = $Data.smart_status.passed
    $row.PowerOnHours = $Data.power_on_time.hours
    $row.TemperatureC = $Data.temperature.current
    $row.WrittenTB = Get-WrittenTB -Data $Data

    $bytes = $Data.user_capacity.bytes
    if (-not $bytes) { $bytes = $Data.nvme_total_capacity }
    if ($bytes) { $row.CapacityGB = [Math]::Round([double]$bytes / 1e9) }

    $health = Get-HealthPercent -Data $Data
    if ($health) {
        $row.HealthPercent = $health.Percent
        $row.HealthSource = $health.Source
    }

    if ($row.Model -match $VirtualModelPattern) { $row.MediaType = 'Virtual' }
    elseif ($row.Protocol -eq 'NVMe') { $row.MediaType = 'SSD' }
    elseif ($null -ne $Data.rotation_rate) {
        if ($Data.rotation_rate -eq 0) { $row.MediaType = 'SSD' } else { $row.MediaType = 'HDD' }
    }
    elseif ($health) { $row.MediaType = 'SSD' }
    else { $row.MediaType = 'Unknown' }

    $replaceNow = New-Object System.Collections.Generic.List[string]
    $replaceSoon = New-Object System.Collections.Generic.List[string]
    $notes = New-Object System.Collections.Generic.List[string]

    if ($Data.smart_status.passed -eq $false -or ($exitStatus -band $EXIT_SMART_FAILING)) {
        $replaceNow.Add('SMART overall health FAILED')
    }

    if ($nvme) {
        $warning = [int]$nvme.critical_warning
        if ($warning -band 0x01) { $replaceNow.Add('NVMe spare space below threshold') }
        if ($warning -band 0x04) { $replaceNow.Add('NVMe reliability degraded') }
        if ($warning -band 0x08) { $replaceNow.Add('NVMe drive is read-only') }
        if ($warning -band 0x02) { $notes.Add('NVMe temperature warning') }
        if ($warning -band 0x10) { $notes.Add('NVMe volatile memory backup failed') }
        if ($nvme.media_errors -gt 0) { $replaceSoon.Add("$($nvme.media_errors) NVMe media errors") }
    }

    foreach ($attribute in $Data.ata_smart_attributes.table) {
        if ($attribute.when_failed -eq 'now') {
            $replaceNow.Add("Attribute $($attribute.id) $($attribute.name) failing")
        }
        $raw = $attribute.raw.value
        if (-not ($raw -gt 0)) { continue }
        if ($attribute.id -eq 197 -and $attribute.name -match 'Pending') {
            $replaceSoon.Add("$raw pending sectors")
        }
        elseif ($attribute.id -eq 198 -and $attribute.name -match 'Uncorrect') {
            $replaceSoon.Add("$raw uncorrectable sectors")
        }
        elseif ($attribute.id -eq 5 -and $attribute.name -match 'Realloc') {
            $notes.Add("$raw reallocated sectors")
        }
    }

    if ($row.MediaType -eq 'Virtual') {
        $row.Status = 'VIRTUAL'
    }
    elseif ($replaceNow.Count -or ($health -and $health.Percent -le $CritPercent)) {
        $row.Status = 'REPLACE NOW'
    }
    elseif ($replaceSoon.Count -or ($health -and $health.Percent -le $WarnPercent)) {
        $row.Status = 'REPLACE SOON'
    }
    elseif (-not $health -and $row.MediaType -ne 'HDD') {
        $row.Status = 'UNKNOWN'
        $notes.Add('drive reports no wear counter; check it with the vendor tool')
    }
    else {
        $row.Status = 'OK'
    }

    $row.Problems = (@($replaceNow) + @($replaceSoon) + @($notes)) -join '; '
    return $row
}

function Get-WindowsDiskSizeGB {
    param([string] $Disk)

    # smartctl names Windows disks /dev/sda, /dev/sdb, ... in PhysicalDrive0, 1, ... order
    if ($Disk -notmatch '^/dev/sd([a-z]+)$') { return $null }
    $index = 0
    foreach ($letter in $Matches[1].ToCharArray()) { $index = $index * 26 + ([int]$letter - [int][char]'a' + 1) }
    $drive = Get-CimInstance Win32_DiskDrive -Filter "Index=$($index - 1)" -ErrorAction SilentlyContinue
    if ($drive.Size) { return [Math]::Round([double]$drive.Size / 1e9) }
    return $null
}

function Get-SsdHealthReport {
    param(
        [Parameter(Mandatory)] [string] $Smartctl,
        [int] $WarnPercent = 30,
        [int] $CritPercent = 10
    )

    $rows = New-Object System.Collections.Generic.List[object]
    $seenSerials = @{}
    $scan = Invoke-Smartctl -Smartctl $Smartctl -Arguments '--scan-open', '-j'

    foreach ($device in $scan.devices) {
        try {
            $data = Invoke-Smartctl -Smartctl $Smartctl -Arguments '-x', '-j', '-d', $device.type, $device.name
            $row = ConvertTo-DiskHealth -Data $data -Disk $device.name -WarnPercent $WarnPercent -CritPercent $CritPercent
            if ($row.Status -ne 'ERROR' -and -not $row.CapacityGB) {
                $row.CapacityGB = Get-WindowsDiskSizeGB -Disk $device.name
            }
        }
        catch {
            $row = New-HealthRow -Disk $device.name -Status 'ERROR' -Problems $_.Exception.Message
        }

        # The same disk can show up twice, e.g. directly and through a RAID driver
        if ($row.Serial) {
            if ($seenSerials.ContainsKey($row.Serial)) { continue }
            $seenSerials[$row.Serial] = $true
        }
        $rows.Add($row)
    }

    if ($rows.Count -eq 0) {
        $rows.Add((New-HealthRow -Status 'ERROR' -Problems 'smartctl found no disks'))
    }
    return $rows
}

# Dot-sourcing only loads the functions above (used for testing)
if ($MyInvocation.InvocationName -eq '.') { return }

try {
    if (-not (Test-Elevated)) { throw 'Must run as Administrator or SYSTEM' }
    $smartctl = Find-Smartctl -Path $SmartctlPath
    $rows = Get-SsdHealthReport -Smartctl $smartctl -WarnPercent $WarnPercent -CritPercent $CritPercent
}
catch {
    $rows = @(New-HealthRow -Status 'ERROR' -Problems $_.Exception.Message)
}

if ($ReportPath) {
    try {
        $target = Join-Path $ReportPath "$env:COMPUTERNAME.csv"
        $temp = "$target.tmp"
        $rows | Export-Csv -LiteralPath $temp -NoTypeInformation -Encoding UTF8 -ErrorAction Stop
        Move-Item -LiteralPath $temp -Destination $target -Force -ErrorAction Stop
    }
    catch {
        Write-Error "Could not write report to ${ReportPath}: $($_.Exception.Message)"
        exit 1
    }
}

$rows
