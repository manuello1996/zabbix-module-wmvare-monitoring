<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

use CDiv;
use CCol;
use CLink;
use CRow;
use CSeverityHelper;
use CSpan;
use CTableInfo;
use CTag;
use CUrl;

class VMwareDvswitchRenderer {
	public static function url(string $hostid, string $uuid, string $section = 'ports', array $filters = []): CUrl {
		$url = (new CUrl('zabbix.php'))->setArgument('action', 'vmware.monitoring.dvswitch')
			->setArgument('hostid', $hostid)->setArgument('uuid', $uuid)->setArgument('section', $section);
		foreach ($filters as $key => $value) { $url->setArgument($key, $value); }
		return $url;
	}

	public static function flag(?bool $value, string $yes = 'Yes', string $no = 'No'): CSpan {
		return new CSpan($value === null ? _('Unknown') : ($value ? _($yes) : _($no)));
	}

	private static function reference(?array $reference) {
		if ($reference === null) { return '-'; }
		$id = match ($reference['type']) {
			'VirtualMachine' => _('VM ID').': '.$reference['id'],
			'HostSystem' => _('Host ID').': '.$reference['id'],
			default => $reference['type'].': '.$reference['id']
		};
		$label = $reference['name'] ?? $id;
		return (new CSpan($label))->setTitle($id);
	}

	private static function counter(string $value): string {
		return preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $value);
	}

	private static function metric(string $name): CSpan {
		$label = ucfirst(strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $name)));
		return (new CSpan($label))->setTitle($name);
	}

	public static function table(array $data): CTableInfo {
		$section = $data['section'];
		$rows = $data['rows'];
		$hostid = (string) $data['vcenter']['hostid']; $uuid = $data['switch']['uuid'];
		$table = (new CTableInfo())->setNoDataMessage(_('No matching records in the collected snapshot.'));
		if ($section === 'ports') {
			$table->setHeader([_('Port'), _('Port group ID'), _('Attached entity / NIC'), _('Hypervisor'),
				_('Link'), _('Blocked'), _('Conflict'), _('VLANs / trunk'), _('MAC / peer'), _('Details')]);
			foreach ($rows as $port) {
				$details_id = 'dvs-port-'.substr(hash('sha256', $hostid.'/'.$uuid.'/'.$port['group'].'/'.$port['key']), 0, 20);
				$fields = (new CTableInfo())->setHeader([_('Reported field'), _('Value')]);
				foreach ($port['fields'] as $path => $value) { $fields->addRow([$path, $value]); }
				$runtime = (new CTableInfo())->setHeader([_('Property'), _('Value')]);
				$runtime->addRow([_('Last status change'), str_starts_with($port['changed'], '0001-') ? _('Unknown') : $port['changed']]);
				$runtime->addRow([_('Status detail'), $port['status']]);
				$runtime->addRow([_('Host-local port'), self::flag($port['local'])]);
				$runtime->addRow([_('DirectPath active'), self::flag($port['directpath'])]);
				$counters = (new CTableInfo())->setHeader([
					_('Port counter'), _('Cumulative value'), _('Port counter'), _('Cumulative value')])
					->setNoDataMessage(_('No counters reported for this port.'));
				$metrics = array_keys($port['statistics']);
				$half = (int) ceil(count($metrics) / 2);
				foreach (array_slice($metrics, 0, $half) as $index => $metric) {
					$other = $metrics[$index + $half] ?? null;
					$counters->addRow([self::metric($metric), self::counter($port['statistics'][$metric]),
						$other !== null ? self::metric($other) : '',
						$other !== null ? self::counter($port['statistics'][$other]) : '']);
				}
				$details = (new CTag('button', true, _('Port details')))->setAttribute('type', 'button')
					->setAttribute('data-vmware-dvs-port-toggle', $details_id)
					->setAttribute('aria-controls', $details_id)->setAttribute('aria-expanded', 'false');
				$table->addRow([
					$port['key'],
					$port['group'] === '' ? '-' : new CLink($port['group'], self::url($hostid, $uuid, 'ports', ['portgroup' => $port['group']])),
					new CDiv([new CDiv(self::reference($port['entity'])), new CDiv($port['nic'].' / '.$port['type'])]),
					self::reference($port['proxy']),
					self::flag($port['up'], 'Up', 'Down')->addClass($port['up'] === true ? ZBX_STYLE_GREEN
						: ($port['up'] === false && $port['connected'] ? ZBX_STYLE_RED : null)),
					self::flag($port['blocked'])->addClass($port['blocked'] === true ? ZBX_STYLE_RED : null),
					self::flag($port['conflict'])->addClass($port['conflict'] === true ? ZBX_STYLE_RED : null),
					new CDiv([new CDiv(implode(', ', $port['vlans']) ?: '-'),
						new CDiv([_('Trunk'), ': ', self::flag($port['trunk'])])]),
					new CDiv([new CDiv($port['mac']), new CDiv($port['peer'])]), $details
				]);
				$panel = new CDiv([
					(new CDiv([
						new CDiv([new CTag('h4', true, _('Runtime details')), $runtime]),
						new CDiv([new CTag('h4', true, _('Port counters')), $counters])
					]))->addClass('vmware-monitoring-dvs-detail-grid'),
					(new CTag('details', true, [new CTag('summary', true, _('All reported fields')), $fields]))
						->addClass('vmware-monitoring-dvs-fields')
				]);
				$table->addRow((new CRow([(new CCol($panel))->setColSpan(10)]))->setId($details_id)
					->setAttribute('hidden', 'hidden')->addClass('vmware-monitoring-dvs-detail-row'));
			}
		}
		elseif ($section === 'portgroups') {
			$table->setHeader([_('Port group ID'), _('Ports'), _('Attached'), _('Links up'), _('Attached, link down'),
				_('Link unknown'), _('Blocked'), _('Conflicts'), _('VLANs'), _('Attached resources')]);
			foreach ($rows as $group) {
				$table->addRow([$group['name'] === '' ? _('No port group reported')
					: new CLink($group['name'], self::url($hostid, $uuid, 'ports', ['portgroup' => $group['name']])),
					(string) $group['ports'], (string) $group['connected'], (string) $group['up'], (string) $group['down'],
					(string) $group['unknown'], (string) $group['blocked'], (string) $group['conflicts'],
					implode(', ', $group['vlans']) ?: '-', (string) count($group['resources'])]);
			}
		}
		elseif ($section === 'resources') {
			$table->setHeader([_('Type'), _('Name'), _('Resource ID'), _('Role'), _('Observed ports'), _('Port groups'), _('NICs')]);
			foreach ($rows as $resource) {
				$type = match ($resource['type']) {
					'VirtualMachine' => _('Virtual machine'), 'HostSystem' => _('Hypervisor'), default => $resource['type']
				};
				$table->addRow([$type, isset($resource['name'])
					? new CLink($resource['name'], self::url($hostid, $uuid, 'ports', ['resource' => $resource['key']])) : '-',
					new CLink($resource['id'], self::url($hostid, $uuid, 'ports', ['resource' => $resource['key']])),
					implode(', ', array_map('_', $resource['roles'])), (string) count($resource['ports']),
					implode(', ', array_filter(array_keys($resource['groups']), static fn($id) => (string) $id !== '')) ?: '-',
					implode(', ', $resource['nics']) ?: '-']);
			}
		}
		elseif ($section === 'statistics') {
			$table->setHeader([_('Counter'), _('Sum of reported port counters'), _('Ports reporting'), _('Unit')]);
			$total = $data['switch']['snapshot']['counts']['ports'] ?? 0;
			foreach ($rows as $counter) {
				$table->addRow([self::metric($counter['name']), self::counter($counter['value']),
					$counter['reported'].' / '.$total,
					str_starts_with($counter['name'], 'bytes') ? _('Bytes')
						: (str_starts_with($counter['name'], 'packets') ? _('Packets') : '-')]);
			}
		}
		else {
			$table->setHeader([_('Time'), _('Severity'), _('Problem'), _('Acknowledged'), _('Suppressed')])
				->setNoDataMessage(_('No active Zabbix problems for this distributed switch.'));
			foreach ($rows as $problem) {
				$table->addRow([date('Y-m-d H:i:s', (int) $problem['clock']),
					(new CSpan(CSeverityHelper::getName((int) $problem['severity'])))
						->addClass(CSeverityHelper::getStatusStyle((int) $problem['severity'])),
					new CLink($problem['name'], (new CUrl('tr_events.php'))->setArgument('triggerid', $problem['objectid'])
						->setArgument('eventid', $problem['eventid'])),
					self::flag((bool) $problem['acknowledged']), self::flag((bool) $problem['suppressed'])]);
			}
		}
		return $table;
	}
}
