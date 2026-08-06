<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

class VMwareFormatter {
	public static function bytes($value, int $decimals = 1): string {
		if ($value === null || $value === '') {
			return '-';
		}

		$bytes = max(0, (float) $value);
		$units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
		$power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;
		$number = $bytes / (1024 ** $power);

		return number_format($number, $power === 0 ? 0 : $decimals).' '.$units[$power];
	}

	public static function percent($value, int $decimals = 1): string {
		return ($value === null || $value === '')
			? '-'
			: number_format((float) $value, $decimals).'%';
	}

	public static function hertz($value, int $decimals = 1): string {
		if ($value === null || $value === '') {
			return '-';
		}

		$hertz = max(0, (float) $value);
		$units = ['Hz', 'kHz', 'MHz', 'GHz', 'THz'];
		$power = $hertz > 0 ? min((int) floor(log($hertz, 1000)), count($units) - 1) : 0;
		$number = $hertz / (1000 ** $power);

		return number_format($number, $power === 0 ? 0 : $decimals).' '.$units[$power];
	}

	public static function duration($seconds): string {
		if ($seconds === null || $seconds === '') {
			return '-';
		}

		$seconds = max(0, (int) $seconds);
		$days = intdiv($seconds, 86400);
		$hours = intdiv($seconds % 86400, 3600);
		$minutes = intdiv($seconds % 3600, 60);

		if ($days > 0) {
			return $days.'d '.$hours.'h';
		}
		if ($hours > 0) {
			return $hours.'h '.$minutes.'m';
		}

		return $minutes.'m';
	}

	public static function sensorReading(?array $reading): string {
		if ($reading === null || !isset($reading['value']) || !is_numeric($reading['value'])) {
			return '-';
		}

		$value = (float) $reading['value'];
		$absolute = abs($value);
		$decimals = $absolute > 0
			? max(0, min(6, 3 - (int) floor(log10($absolute))))
			: 0;
		$formatted = number_format($value, $decimals, '.', ' ');
		if ($decimals > 0) {
			$formatted = rtrim(rtrim($formatted, '0'), '.');
		}

		$units = self::sensorReadingUsesDiscreteUnit($reading)
			? ''
			: trim((string) ($reading['units'] ?? ''));
		$units = [
			'Watts' => 'W',
			'Volts' => 'V',
			'Amps' => 'A',
			'Hertz' => 'Hz',
			'Degrees C' => '°C',
			'Degrees F' => '°F',
			'Percentage' => '%',
			'Percent' => '%'
		][$units] ?? $units;

		if ($units === '%') {
			return $formatted.$units;
		}

		return $units !== '' ? $formatted.' '.$units : $formatted;
	}

	public static function sensorReadingUsesDiscreteUnit(?array $reading): bool {
		if ($reading === null) {
			return false;
		}

		return in_array(mb_strtolower(trim((string) ($reading['units'] ?? ''))), [
			'unspecified',
			'assert-discrete',
			'sensor-discrete',
			'redundancy-discrete'
		], true);
	}

	public static function vcenterHealth($value): array {
		return self::mappedState($value, [
			'0' => ['Green', 'running'],
			'1' => ['Yellow', 'paused'],
			'2' => ['Orange', 'paused'],
			'3' => ['Red', 'stopped'],
			'4' => ['Gray', 'unknown'],
			'5' => ['Unknown', 'unknown'],
			'6' => ['Not available', 'unknown']
		]);
	}

	public static function hypervisorHealth($value): array {
		return self::mappedState($value, [
			'0' => ['Gray', 'unknown'],
			'1' => ['Green', 'running'],
			'2' => ['Yellow', 'paused'],
			'3' => ['Red', 'stopped']
		]);
	}

	public static function connectionState($value): array {
		return self::mappedState($value, [
			'0' => ['Connected', 'running'],
			'1' => ['Disconnected', 'stopped'],
			'2' => ['Not responding', 'stopped'],
			'3' => ['Unknown', 'unknown']
		]);
	}

	public static function vmPowerState($value): array {
		return self::mappedState($value, [
			'0' => ['Powered off', 'stopped'],
			'1' => ['Powered on', 'running'],
			'2' => ['Suspended', 'paused']
		]);
	}

	public static function vmState($value): array {
		return self::mappedState($value, [
			'0' => ['Not running', 'stopped'],
			'1' => ['Resetting', 'paused'],
			'2' => ['Running', 'running'],
			'3' => ['Shutting down', 'paused'],
			'4' => ['Standby', 'paused'],
			'5' => ['Unknown', 'unknown']
		]);
	}

	public static function toolsStatus($value): string {
		$map = [
			'0' => 'Executing scripts',
			'1' => 'Not running',
			'2' => 'Running',
			'10' => 'Unknown'
		];

		return ($value === null || $value === '') ? '-' : ($map[(string) $value] ?? (string) $value);
	}

	private static function mappedState($value, array $map): array {
		if ($value === null || $value === '') {
			return ['text' => 'Unknown', 'kind' => 'unknown'];
		}

		$key = (string) $value;
		if (isset($map[$key])) {
			return ['text' => $map[$key][0], 'kind' => $map[$key][1]];
		}

		return ['text' => $key, 'kind' => 'unknown'];
	}
}
