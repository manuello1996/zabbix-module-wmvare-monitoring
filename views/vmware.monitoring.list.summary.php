<?php declare(strict_types = 0);

$makeStat = static function (string $modifier, string $label, $value): CDiv {
	return (new CDiv([
		(new CDiv($label))->addClass('vmware-monitoring-statseg-label'),
		(new CDiv((new CSpan((string) $value))->addClass('vmware-monitoring-card-value')))
			->addClass('vmware-monitoring-statseg-figure')
	]))
		->addClass('vmware-monitoring-statseg')
		->addClass('vmware-monitoring-card-'.$modifier);
};

$stats = (new CDiv([
	(new CDiv([
		$makeStat('nodes', _('vCenters'), $data['totals']['vcenters']),
		$makeStat('total', _('Hypervisors'), $data['totals']['hypervisors']),
		$makeStat('total', _('Virtual machines'), $data['totals']['vms']),
		$makeStat('memory', _('Unique datastores'), $data['totals']['datastores'])
	]))->addClass('vmware-monitoring-statstrip')
]))->addClass('vmware-monitoring-section');
$stats->show();
