<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

// Also supports standalone test harnesses that do not register Zabbix's module loader.
spl_autoload_register(static function (string $class): void {
	$prefix = __NAMESPACE__.'\\';
	if (!str_starts_with($class, $prefix)) { return; }
	$name = substr($class, strlen($prefix));
	if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) && is_file(__DIR__.'/'.$name.'.php')) {
		require_once __DIR__.'/'.$name.'.php';
	}
});
