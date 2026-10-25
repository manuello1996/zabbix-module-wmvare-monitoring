<?php declare(strict_types = 0);
use Modules\VMwareMonitoring\Includes\Common\Summary;

require_once dirname(__DIR__).'/includes/common/bootstrap.php';

$makeStat = static function (string $modifier, string $label, $value): CDiv {
	return Summary::card($label, (string) $value, ['modifier' => $modifier]);
};

$stats = (new CDiv([
	Summary::strip([
		$makeStat('nodes', _('vCenters'), $data['totals']['vcenters']),
		$makeStat('total', _('Hypervisors'), $data['totals']['hypervisors']),
		$makeStat('total', _('Virtual machines'), $data['totals']['vms']),
		$makeStat('memory', _('Unique datastores'), $data['totals']['datastores'])
	])
]))->addClass('vmware-monitoring-section');
$stats->show();
