package GLPI::Agent::Task::Inventory::Generic::Storages::SmartHealth;

use strict;
use warnings;

use parent 'GLPI::Agent::Task::Inventory::Module';

use Cpanel::JSON::XS;
use UNIVERSAL::require;

use GLPI::Agent::Tools;

# Dedicated category so this module can be disabled alone with no-category=storage_health
use constant    category    => "storage_health";

# This module only completes STORAGES entries, so it must run after any module
# which can add them. Only installed modules are kept: the agent aborts the whole
# inventory when a listed module doesn't exist, and agent versions may differ.
our $runAfterIfEnabled = [ grep { _moduleExists($_) } qw(
    GLPI::Agent::Task::Inventory::AIX::Storages
    GLPI::Agent::Task::Inventory::BSD::Storages
    GLPI::Agent::Task::Inventory::BSD::Storages::Megaraid
    GLPI::Agent::Task::Inventory::Generic::Storages::3ware
    GLPI::Agent::Task::Inventory::Generic::Storages::HP
    GLPI::Agent::Task::Inventory::Generic::Storages::HpWithSmartctl
    GLPI::Agent::Task::Inventory::HPUX::Storages
    GLPI::Agent::Task::Inventory::Linux::Storages
    GLPI::Agent::Task::Inventory::Linux::Storages::Adaptec
    GLPI::Agent::Task::Inventory::Linux::Storages::Lsilogic
    GLPI::Agent::Task::Inventory::Linux::Storages::Megacli
    GLPI::Agent::Task::Inventory::Linux::Storages::MegacliWithSmartctl
    GLPI::Agent::Task::Inventory::Linux::Storages::Megaraid
    GLPI::Agent::Task::Inventory::Linux::Storages::ServeRaid
    GLPI::Agent::Task::Inventory::MacOS::Storages
    GLPI::Agent::Task::Inventory::Solaris::Storages
    GLPI::Agent::Task::Inventory::Win32::Storages
    GLPI::Agent::Task::Inventory::Win32::Storages::HP
)];

# ATA attributes whose normalized value counts down from 100 as the SSD wears out,
# as named by smartctl drive database. Explicit life counters are checked first.
my @life_attributes = (
    qr/life.*(?:left|remain)|remain.*life/i,
    qr/^Media_Wearout_Indicator$/i,
    qr/^Wear_Leveling_Count$/i,
);

# smartctl exit status bits, see smartctl(8) RETURN VALUES
use constant {
    EXIT_CMDLINE_ERROR  => 0x01,
    EXIT_OPEN_FAILED    => 0x02,
    EXIT_SMART_FAILING  => 0x08,
};

sub _moduleExists {
    my ($module) = @_;

    my $file = module2file($module);

    return any { !ref($_) && -f "$_/$file" } @INC;
}

sub isEnabled {
    return canRun('smartctl');
}

sub doInventory {
    my (%params) = @_;

    my $inventory = $params{inventory};
    my $logger    = $params{logger};

    my $storages = $inventory->getSection('STORAGES');
    return unless ref($storages) eq 'ARRAY' && @{$storages};

    my (%done, $volumes);
    foreach my $device (_getDevices(logger => $logger)) {
        my $data = _getSmartData(
            command => "smartctl -x -j -d $device->{type} \"$device->{name}\"",
            logger  => $logger,
        );

        my $health = _getHealth($data);
        unless ($health) {
            $logger->debug2("No SMART health data for $device->{name}") if $logger;
            next;
        }

        my $storage = _findStorage(
            storages => $storages,
            device   => $device->{name},
            serial   => $data->{serial_number},
            done     => \%done,
        );
        unless ($storage) {
            $logger->debug2("No storage found to report $device->{name} SMART health") if $logger;
            next;
        }

        # STORAGES entries are only checked by addEntry(), we complete them in place
        foreach my $key (keys(%{$health})) {
            $storage->{$key} = $health->{$key};
        }

        # Drive letters or mount points on the disk, to know which disk to replace
        $volumes //= _getVolumes(logger => $logger);
        my $disk = _getDiskKey($storage->{NAME}, $device->{name});
        $storage->{SMART_VOLUMES} = join(', ', @{$volumes->{$disk}})
            if defined($disk) && $volumes->{$disk};
    }
}

sub _decode {
    my ($json) = @_;

    return unless defined($json) && length($json);

    my $data;
    eval {
        $data = decode_json($json);
    };

    return ref($data) eq 'HASH' ? $data : undef;
}

sub _getDevices {
    my (%params) = (
        command => 'smartctl --scan-open -j',
        @_
    );

    my $data = _decode(scalar(getAllLines(%params)))
        or return;

    return unless ref($data->{devices}) eq 'ARRAY';

    # Only keep sane device names and types as they are used in a command line
    return grep {
        ref($_) eq 'HASH' &&
        defined($_->{name}) && $_->{name} =~ m{^[\w/.,:-]+$} &&
        defined($_->{type}) && $_->{type} =~ m{^[\w,.+-]+$}
    } @{$data->{devices}};
}

sub _getSmartData {
    my (%params) = @_;

    return _decode(scalar(getAllLines(%params)));
}

sub _getHealth {
    my ($data) = @_;

    return unless ref($data) eq 'HASH';

    my $exit_status = ref($data->{smartctl}) eq 'HASH' ? $data->{smartctl}->{exit_status} : undef;
    $exit_status = 0 unless defined($exit_status) && $exit_status =~ /^\d+$/;

    # Device can't be opened or is in low-power mode: no usable data
    return if $exit_status & (EXIT_CMDLINE_ERROR | EXIT_OPEN_FAILED);

    my $nvme = ref($data->{nvme_smart_health_information_log}) eq 'HASH' ?
        $data->{nvme_smart_health_information_log} : undef;

    my $attributes = ref($data->{ata_smart_attributes}) eq 'HASH'
        && ref($data->{ata_smart_attributes}->{table}) eq 'ARRAY' ?
        $data->{ata_smart_attributes}->{table} : [];

    my %health;

    my ($percent, $source) = _getRemainingLife($data, $nvme, $attributes);
    if (defined($percent)) {
        $health{SMART_HEALTH}        = $percent;
        $health{SMART_HEALTH_SOURCE} = $source;
    }

    if (ref($data->{smart_status}) eq 'HASH' && defined($data->{smart_status}->{passed})) {
        $health{SMART_STATUS} = $data->{smart_status}->{passed} ? 'PASSED' : 'FAILED';
    }
    $health{SMART_STATUS} = 'FAILED' if $exit_status & EXIT_SMART_FAILING;

    # Only report disks with at least a health percentage or a SMART status
    return unless defined($health{SMART_HEALTH}) || defined($health{SMART_STATUS});

    my $protocol = ref($data->{device}) eq 'HASH' ? $data->{device}->{protocol} // '' : '';
    if ($protocol eq 'NVMe') {
        $health{SMART_TYPE} = 'SSD';
    } elsif (defined($data->{rotation_rate}) && $data->{rotation_rate} =~ /^\d+$/) {
        $health{SMART_TYPE} = $data->{rotation_rate} ? 'HDD' : 'SSD';
    } elsif (defined($percent)) {
        $health{SMART_TYPE} = 'SSD';
    }

    _setInteger(\%health, SMART_POWER_ON_HOURS => ref($data->{power_on_time}) eq 'HASH' ?
        $data->{power_on_time}->{hours} : undef);
    _setInteger(\%health, SMART_TEMPERATURE => ref($data->{temperature}) eq 'HASH' ?
        $data->{temperature}->{current} : undef);
    _setInteger(\%health, SMART_WRITTEN => _getWrittenMB($data, $nvme));

    if ($nvme) {
        _setInteger(\%health, SMART_CRITICAL_WARNING => $nvme->{critical_warning});
        _setInteger(\%health, SMART_MEDIA_ERRORS => $nvme->{media_errors});
    }

    my @failing;
    foreach my $attribute (@{$attributes}) {
        next unless ref($attribute) eq 'HASH' && defined($attribute->{id});
        my $name = $attribute->{name} // '';
        push @failing, "$attribute->{id} $name"
            if defined($attribute->{when_failed}) && $attribute->{when_failed} eq 'now';
        if ($attribute->{id} == 5 && $name =~ /Realloc/i) {
            _setInteger(\%health, SMART_REALLOCATED_SECTORS => _getRawValue($attribute));
        } elsif ($attribute->{id} == 197 && $name =~ /Pending/i) {
            _setInteger(\%health, SMART_PENDING_SECTORS => _getRawValue($attribute));
        } elsif ($attribute->{id} == 198 && $name =~ /Uncorrect/i) {
            _setInteger(\%health, SMART_UNCORRECTABLE_SECTORS => _getRawValue($attribute));
        }
    }
    $health{SMART_FAILING_ATTRIBUTES} = join(', ', @failing) if @failing;

    return \%health;
}

# Remaining life in percent, like CrystalDiskInfo "Health", and where it comes from
sub _getRemainingLife {
    my ($data, $nvme, $attributes) = @_;

    return (_remaining($nvme->{percentage_used}), 'NVMe Percentage Used')
        if $nvme && defined($nvme->{percentage_used}) && $nvme->{percentage_used} =~ /^\d+$/;

    # ATA Device Statistics page 7, "Percentage Used Endurance Indicator" (ACS-3 standard)
    my $used = _getDeviceStatistic($data, 7, 0x008);
    return (_remaining($used), 'Device Statistics (Percentage Used)')
        if defined($used);

    # Vendor specific attributes: normalized value is 1-100 when it is a percentage
    foreach my $pattern (@life_attributes) {
        foreach my $attribute (@{$attributes}) {
            next unless ref($attribute) eq 'HASH' && defined($attribute->{id}) && $attribute->{id} >= 100;
            next unless defined($attribute->{name}) && $attribute->{name} =~ $pattern;
            my $value = $attribute->{value};
            next unless defined($value) && $value =~ /^\d+$/ && $value >= 1 && $value <= 100;
            return (int($value), "Attribute $attribute->{id} $attribute->{name}");
        }
    }

    # Value computed by smartctl 7.5 and later, used for SAS SSDs
    $used = ref($data->{endurance_used}) eq 'HASH' ? $data->{endurance_used}->{current_percent} : undef;
    return (_remaining($used), 'smartctl endurance_used')
        if defined($used) && $used =~ /^\d+$/;

    # Same SAS SSD value with older smartctl versions
    $used = $data->{scsi_percentage_used_endurance_indicator};
    return (_remaining($used), 'SCSI Percentage Used')
        if defined($used) && $used =~ /^\d+$/;

    return;
}

sub _remaining {
    my ($used) = @_;

    my $remaining = 100 - int($used);
    return $remaining < 0 ? 0 : $remaining;
}

sub _getDeviceStatistic {
    my ($data, $page, $offset) = @_;

    my $statistics = $data->{ata_device_statistics};
    return unless ref($statistics) eq 'HASH' && ref($statistics->{pages}) eq 'ARRAY';

    foreach my $statistics_page (@{$statistics->{pages}}) {
        next unless ref($statistics_page) eq 'HASH' && ref($statistics_page->{table}) eq 'ARRAY';
        next unless defined($statistics_page->{number}) && $statistics_page->{number} == $page;
        foreach my $entry (@{$statistics_page->{table}}) {
            next unless ref($entry) eq 'HASH' && defined($entry->{offset}) && $entry->{offset} == $offset;
            next unless ref($entry->{flags}) eq 'HASH' && $entry->{flags}->{valid};
            return $entry->{value} if defined($entry->{value}) && $entry->{value} =~ /^\d+$/;
        }
    }

    return;
}

sub _getWrittenMB {
    my ($data, $nvme) = @_;

    # NVMe data units are 1000 x 512 bytes
    return int($nvme->{data_units_written} * 512 / 1000)
        if $nvme && defined($nvme->{data_units_written}) && $nvme->{data_units_written} =~ /^\d+$/;

    # ATA Device Statistics page 1, "Logical Sectors Written"
    my $sectors = _getDeviceStatistic($data, 1, 0x018)
        or return;
    my $block_size = $data->{logical_block_size};
    $block_size = 512 unless $block_size && $block_size =~ /^\d+$/;

    return int($sectors * $block_size / 1_000_000);
}

sub _getRawValue {
    my ($attribute) = @_;

    my $raw = $attribute->{raw};
    return unless ref($raw) eq 'HASH';

    my $value = $raw->{value};
    return unless defined($value) && $value =~ /^\d+$/;

    # Some vendors pack several counters in the 48 bits raw value: keep the first one
    if ($value > 0xFFFFFFFF && defined($raw->{string}) && $raw->{string} =~ /^(\d+)/) {
        return $1;
    }

    return $value;
}

sub _setInteger {
    my ($health, $key, $value) = @_;

    $health->{$key} = int($value) if defined($value) && $value =~ /^\d+$/;
}

sub _findStorage {
    my (%params) = @_;

    my $done = $params{done} // {};
    my @candidates = grep { !$done->{$_} } @{$params{storages}};

    # Prefer serial number when it designates only one storage
    my $serial = _normalizeSerial($params{serial});
    my @matches = $serial ? grep { _serialMatch($serial, $_) } @candidates : ();

    # Otherwise use device name
    @matches = grep { _nameMatch($params{device}, $_->{NAME}, $params{osname}) } @candidates
        unless @matches == 1;

    return unless @matches == 1;

    $done->{$matches[0]} = 1;

    return $matches[0];
}

sub _normalizeSerial {
    my ($serial) = @_;

    return '' unless defined($serial);
    $serial = uc($serial);
    $serial =~ s/[^A-Z0-9 ]//g;
    $serial =~ s/^\s+|\s+$//g;

    return $serial;
}

sub _serialMatch {
    my ($serial, $storage) = @_;

    my $storage_serial = _normalizeSerial($storage->{SERIAL} // $storage->{SERIALNUMBER});
    return 0 unless length($storage_serial) >= 4;

    $storage_serial =~ s/\s+//g;
    my ($first) = $serial =~ /^(\S+)/;
    (my $full = $serial) =~ s/\s+//g;

    # Windows inventory only keeps the first word of the serial number
    return $storage_serial eq $full || $storage_serial eq $first;
}

sub _nameMatch {
    my ($device, $name, $osname) = @_;

    return 0 unless defined($device) && defined($name);

    if (($osname // OSNAME) eq 'MSWin32') {
        my $index = _getWindowsDiskIndex($device);
        my $number = _getWindowsDiskNumber($name);
        return defined($index) && defined($number) && $number == $index;
    }

    my ($basename) = $device =~ m{^/dev/(.+)$}
        or return 0;
    return 1 if $name eq $basename;

    # smartctl uses NVMe controller device, storage name is its first namespace
    return $basename =~ /^nvme\d+$/ && $name eq $basename . 'n1';
}

# smartctl names Windows disks /dev/sda, /dev/sdb, ... in PhysicalDrive0, 1, ... order
sub _getWindowsDiskIndex {
    my ($device) = @_;

    my ($letters) = ($device // '') =~ m{^/dev/sd([a-z]+)$}
        or return;
    my $index = 0;
    foreach my $letter (split(//, $letters)) {
        $index = $index * 26 + ord($letter) - ord('a') + 1;
    }

    return $index - 1;
}

# Windows storages are named PhysicalDisk0 or \\.\PHYSICALDRIVE0
sub _getWindowsDiskNumber {
    my ($name) = @_;

    my ($number) = ($name // '') =~ /^(?:PhysicalDisk|\\\\\.\\PhysicalDrive)(\d+)$/i
        or return;

    return $number;
}

# Key of a disk in _getVolumes() result: Windows disk number or Linux block device name
sub _getDiskKey {
    my ($name, $device, $osname) = @_;

    if (($osname // OSNAME) eq 'MSWin32') {
        return _getWindowsDiskNumber($name) // _getWindowsDiskIndex($device);
    }

    return $name if defined($name) && $name =~ /^[\w.-]+$/;

    my ($basename) = ($device // '') =~ m{^/dev/(\w+)$}
        or return;

    return $basename =~ /^nvme\d+$/ ? $basename . 'n1' : $basename;
}

# Volumes on each disk, like "C: (Windows)" on Windows or "/home (data)" on Linux
sub _getVolumes {
    my (%params) = @_;

    my $osname = delete($params{osname}) // OSNAME;

    return _getWindowsVolumes(%params) if $osname eq 'MSWin32';
    return _getLinuxVolumes(%params) if $osname eq 'linux';

    return {};
}

sub _getWindowsVolumes {
    my (%params) = @_;

    # Tests provide WMI objects
    my $partitions   = $params{partitions};
    my $logicaldisks = $params{logicaldisks};
    unless ($partitions) {
        GLPI::Agent::Tools::Win32->require()
            or return {};
        $partitions = [ GLPI::Agent::Tools::Win32::getWMIObjects(
            class      => 'Win32_LogicalDiskToPartition',
            properties => [ qw/Antecedent Dependent/ ],
            logger     => $params{logger},
        ) ];
        $logicaldisks = [ GLPI::Agent::Tools::Win32::getWMIObjects(
            class      => 'Win32_LogicalDisk',
            properties => [ qw/DeviceID VolumeName/ ],
            logger     => $params{logger},
        ) ];
    }

    my %labels;
    foreach my $logicaldisk (@{$logicaldisks // []}) {
        next unless ref($logicaldisk) eq 'HASH' && defined($logicaldisk->{DeviceID});
        $labels{uc($logicaldisk->{DeviceID})} = $logicaldisk->{VolumeName};
    }

    my %volumes;
    foreach my $link (@{$partitions}) {
        next unless ref($link) eq 'HASH';
        # Antecedent: \\HOST\root\cimv2:Win32_DiskPartition.DeviceID="Disk #0, Partition #1"
        # Dependent:  \\HOST\root\cimv2:Win32_LogicalDisk.DeviceID="C:"
        my ($disk) = ($link->{Antecedent} // '') =~ /Disk #(\d+),/
            or next;
        my ($letter) = ($link->{Dependent} // '') =~ /DeviceID="([A-Za-z]:)"/
            or next;
        $letter = uc($letter);
        push @{$volumes{$disk}}, _getVolumeName($letter, $labels{$letter});
    }

    return { map { $_ => [ sort @{$volumes{$_}} ] } keys(%volumes) };
}

sub _getLinuxVolumes {
    my (%params) = (
        command => 'lsblk -J -o NAME,MOUNTPOINT,LABEL',
        @_
    );

    my $data = _decode(scalar(getAllLines(%params)));
    return {} unless $data && ref($data->{blockdevices}) eq 'ARRAY';

    my %volumes;
    foreach my $device (@{$data->{blockdevices}}) {
        next unless ref($device) eq 'HASH' && defined($device->{name});
        # LVM volume groups spanning several partitions are listed under each of them
        my %seen;
        my @mounts = grep { !$seen{$_}++ } _getMounts($device);
        $volumes{$device->{name}} = \@mounts if @mounts;
    }

    return \%volumes;
}

# Mount points of a block device and what it holds: partitions, RAID, LVM, encryption
sub _getMounts {
    my ($device) = @_;

    my @mounts;
    push @mounts, _getVolumeName($device->{mountpoint}, $device->{label})
        if defined($device->{mountpoint}) && $device->{mountpoint} =~ m{^/};

    if (ref($device->{children}) eq 'ARRAY') {
        push @mounts, map { _getMounts($_) } grep { ref($_) eq 'HASH' } @{$device->{children}};
    }

    return @mounts;
}

sub _getVolumeName {
    my ($volume, $label) = @_;

    $label = trimWhitespace($label) if defined($label);

    return defined($label) && length($label) ? "$volume ($label)" : $volume;
}

1;
