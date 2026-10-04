<?php

/**
 * Disk health plugin for GLPI
 *
 * @license   MIT
 */

/**
 * Email alert listing the drives to replace in one entity, raised by the diskhealthalert automatic action
 */
class PluginDiskhealthNotificationTargetDisk extends NotificationTarget
{
    public function getEvents()
    {
        return ['alert' => __('Drives to replace', 'diskhealth')];
    }

    public function addDataForTemplate($event, $options = [])
    {
        $events   = $this->getAllEvents();
        $usertype = $options['additionnaloption']['usertype'] ?? NotificationTarget::GLPI_USER;
        $labels   = PluginDiskhealthDisk::getStatusLabels();
        $drives   = $options['items'] ?? [];

        $this->data['##diskhealth.action##'] = $events[$event] ?? '';
        $this->data['##diskhealth.entity##'] = Dropdown::getDropdownName('glpi_entities', $options['entities_id'] ?? 0);
        $this->data['##diskhealth.count##']  = count($drives);
        $this->data['##diskhealth.url##']    = PluginDiskhealthAlert::getListUrl(PluginDiskhealthAlert::ALERT_STATUSES, true);

        $this->data['disks'] = [];
        foreach ($drives as $drive) {
            $this->data['disks'][] = [
                '##disk.computer##'    => $drive['computer_name'],
                '##disk.computerurl##' => $this->formatURL($usertype, 'Computer_' . $drive['computers_id']),
                '##disk.model##'       => $drive['model'],
                '##disk.serial##'      => $drive['serial'],
                '##disk.health##'      => $drive['health'] === null ? __('not reported', 'diskhealth') : $drive['health'] . '%',
                '##disk.status##'      => $labels[(int) $drive['status']] ?? '',
                '##disk.problems##'    => $drive['problems'],
                '##disk.lastupdate##'  => Html::convDateTime($drive['date_mod']),
            ];
        }

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags()
    {
        $tags = [
            'diskhealth.action' => _n('Event', 'Events', 1),
            'diskhealth.entity' => Entity::getTypeName(1),
            'diskhealth.count'  => __('Number of drives', 'diskhealth'),
            'diskhealth.url'    => __('See all drives to replace', 'diskhealth'),
            'disk.computer'     => Computer::getTypeName(1),
            'disk.computerurl'  => __('URL'),
            'disk.model'        => __('Hard drive', 'diskhealth'),
            'disk.serial'       => __('Serial number'),
            'disk.health'       => __('Health', 'diskhealth'),
            'disk.status'       => __('Status'),
            'disk.problems'     => __('Problems', 'diskhealth'),
            'disk.lastupdate'   => __('Last update'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList([
                'tag'   => $tag,
                'label' => $label,
                'value' => true,
            ]);
        }

        $this->addTagToList([
            'tag'     => 'disks',
            'label'   => __('Drives', 'diskhealth'),
            'value'   => false,
            'foreach' => true,
        ]);

        asort($this->tag_descriptions);
    }
}
