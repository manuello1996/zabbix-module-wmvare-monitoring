<?php declare(strict_types = 0);

// Generated from zabbix-module-common. Edit the central source, then synchronize.
namespace Modules\VMwareMonitoring\Includes\Common;

class Formatting {
	/** Callers retain their own missing-value policy and unit labels. */
	public static function bytes($value, int $decimals = 1,
			array $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']): string {
		$value = max(0, (float) $value);
		$power = $value > 0 ? max(0, min(count($units) - 1, (int) floor(log($value, 1024)))) : 0;
		return number_format($value / (1024 ** $power), $power === 0 ? 0 : $decimals).' '.$units[$power];
	}

	public static function duration($seconds): string {
		$seconds = max(0, (int) $seconds);
		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$minutes = intdiv($seconds % 3600, 60);
		return $days > 0 ? $days.'d '.$hours.'h' : ($hours > 0 ? $hours.'h '.$minutes.'m' : $minutes.'m');
	}

	public static function binaryRate($value): string {
		$value = max(0.0, (float) $value);
		foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
			if ($value < 1024 || $unit === 'GB') {
				return ($unit === 'B' ? (string) round($value) : sprintf('%.1f', $value)).' '.$unit.'/s';
			}
			$value /= 1024;
		}
		return '-';
	}
}
