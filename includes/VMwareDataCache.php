<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes;

/** Monitoring payloads belong on the server, not in Zabbix's session cookie. */
class VMwareDataCache {
	private const GENERATION = 'vmware_monitoring_cache_generation';
	private const MAX_BYTES = 2097152;

	public static function get(string $key, int $ttl, callable $loader) {
		\CSessionHelper::unset(['vmware_monitoring_data_cache']);
		$sessionid = (string) (\CWebUser::$data['sessionid'] ?? '');
		$userid = (string) (\CWebUser::$data['userid'] ?? '');
		$directory = self::directory();
		if ($sessionid === '' || $directory === null) { return $loader(); }
		$generation = \CSessionHelper::get(self::GENERATION);
		if (!is_string($generation) || !preg_match('/^[a-f0-9]{16}$/', $generation)) {
			$generation = bin2hex(random_bytes(8));
			\CSessionHelper::set(self::GENERATION, $generation);
		}
		$file = $directory.'/'.hash('sha256', $userid.'/'.$sessionid.'/'.$generation.'/'.$key).'.json';
		$now = time();
		$size = @filesize($file);
		if ($size !== false && $size <= self::MAX_BYTES) {
			$contents = @file_get_contents($file);
			$entry = $contents !== false ? json_decode($contents, true) : null;
			if (is_array($entry) && is_int($entry['expires'] ?? null) && $entry['expires'] > $now
					&& array_key_exists('data', $entry)) { return $entry['data']; }
		}
		$data = $loader();
		$encoded = json_encode(['expires' => $now + min(600, max(1, $ttl)), 'data' => $data]);
		if ($encoded !== false && strlen($encoded) <= self::MAX_BYTES) {
			$temp = @tempnam($directory, 'write-');
			if ($temp !== false) {
				if (@file_put_contents($temp, $encoded) === strlen($encoded)) { @rename($temp, $file); }
				if (is_file($temp)) { @unlink($temp); }
			}
		}
		$visited = 0;
		foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
			if (++$visited > 64) { break; }
			$modified = @filemtime($entry->getPathname());
			if ($entry->isFile() && $modified !== false && $modified < $now - 600) { @unlink($entry->getPathname()); }
		}
		return $data;
	}

	public static function clear(): void {
		\CSessionHelper::unset(['vmware_monitoring_data_cache', self::GENERATION]);
	}

	private static function directory(): ?string {
		if (PHP_OS_FAMILY === 'Windows') { return null; }
		$directory = sys_get_temp_dir().'/zabbix-vmware-cache-v1-'.substr(hash('sha256', __DIR__), 0, 16);
		if (!is_dir($directory) && !@mkdir($directory, 0700)) { return null; }
		if (is_link($directory) || (fileperms($directory) & 0077) !== 0 || !is_writable($directory)) { return null; }
		return $directory;
	}
}
