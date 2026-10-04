<#
.SYNOPSIS
    Combines the per-PC CSVs written by Get-SsdHealth.ps1 into one list, worst disks first.

.DESCRIPTION
    Reads every <COMPUTERNAME>.csv in ReportPath and writes SsdHealth-Summary.csv, sorted by
    status (REPLACE NOW, REPLACE SOON, ERROR, UNKNOWN, OK, VIRTUAL) and then lowest health
    first. Adds a ReportAgeDays column so PCs that stopped reporting stand out.

.PARAMETER ReportPath
    The folder Get-SsdHealth.ps1 writes to.

.PARAMETER OutFile
    Where to write the combined CSV. Default: SsdHealth-Summary.csv in ReportPath.

.PARAMETER StaleDays
    Reports older than this many days are counted as stale in the console summary. Default 14.

.EXAMPLE
    .\Merge-SsdHealthReports.ps1 -ReportPath \\fileserver\SsdHealth$\reports
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string] $ReportPath,
    [string] $OutFile,
    [int] $StaleDays = 14
)

$summaryName = 'SsdHealth-Summary.csv'
if (-not $OutFile) { $OutFile = Join-Path $ReportPath $summaryName }

$statusOrder = @{ 'REPLACE NOW' = 0; 'REPLACE SOON' = 1; 'ERROR' = 2; 'UNKNOWN' = 3; 'OK' = 4; 'VIRTUAL' = 5 }
$now = Get-Date

$rows = foreach ($file in Get-ChildItem -LiteralPath $ReportPath -Filter '*.csv' -File) {
    if ($file.Name -eq $summaryName) { continue }
    $age = [int][Math]::Floor(($now - $file.LastWriteTime).TotalDays)
    foreach ($row in Import-Csv -LiteralPath $file.FullName) {
        $row | Add-Member -NotePropertyName ReportAgeDays -NotePropertyValue $age -PassThru
    }
}

if (-not $rows) {
    Write-Warning "No reports found in $ReportPath"
    return
}

$sorted = $rows | Sort-Object `
    @{ Expression = { $order = $statusOrder[$_.Status]; if ($null -eq $order) { 9 } else { $order } } },
    @{ Expression = { if ($_.HealthPercent -ne '') { [int]$_.HealthPercent } else { 999 } } },
    ComputerName

$sorted | Export-Csv -LiteralPath $OutFile -NoTypeInformation -Encoding UTF8

$pcCount = @($sorted | Select-Object -ExpandProperty ComputerName -Unique).Count
$stale = @($sorted | Where-Object { $_.ReportAgeDays -gt $StaleDays } |
    Select-Object -ExpandProperty ComputerName -Unique)

"$($sorted.Count) disks from $pcCount PCs"
$sorted | Group-Object Status | Select-Object Count, @{ n = 'Status'; e = { $_.Name } } | Format-Table -AutoSize

$urgent = @($sorted | Where-Object { $_.Status -in 'REPLACE NOW', 'REPLACE SOON' })
if ($urgent.Count) {
    $urgent | Format-Table ComputerName, Model, Serial, HealthPercent, Status, Problems -AutoSize -Wrap
}
if ($stale.Count) {
    "$($stale.Count) PCs have not reported for more than $StaleDays days: $($stale -join ', ')"
}
"Summary written to $OutFile"
