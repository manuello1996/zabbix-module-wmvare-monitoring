<?php
/** php dev/tests/dvswitches.php /usr/share/zabbix (real HTML classes, mocked read API). */
$ui = $argv[1] ?? '';
if (!is_file($ui.'/include/defines.inc.php')) { throw new RuntimeException('Provide a Zabbix UI directory.'); }
if (!function_exists('_')) { function _($text) { return $text; } }
function _s($text, ...$args) { return sprintf($text, ...$args); }
function _x($text, $context) { return $text; }
function timeUnitToSeconds($value) { return 86400; }
class CSettingsHelper {
	public const HISTORY_PERIOD = 'history_period';
	public const SEVERITY_NAME_2 = 'severity_name_2';
	public static function get($key) { return $key === self::HISTORY_PERIOD ? '1d' : 'Warning'; }
}
require $ui.'/include/defines.inc.php';
require $ui.'/include/translateDefines.inc.php';
require $ui.'/include/classes/core/CAutoloader.php';
$loader = new CAutoloader();
$paths = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ui.'/include/classes', FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::SELF_FIRST) as $entry) {
	if ($entry->isDir()) { $paths[] = $entry->getPathname(); }
}
$loader->addNamespace('', $paths);
$loader->addNamespace('Modules\\VMwareMonitoring', [dirname(__DIR__, 2)]);
$loader->register();
use Modules\VMwareMonitoring\Includes\VMwareCollector as Collector;
use Modules\VMwareMonitoring\Includes\VMwareTabRenderer as Renderer;
class API {
	public static array $items = [];
	public static array $rules = [];
	public static array $triggers = [];
	public static array $problems = [];
	public static array $vm_inventory = [];
	public static array $host_inventory = [];
	public static array $calls = [];
	private string $kind;
	public static function Item() { $api = new self(); $api->kind = 'items'; return $api; }
	public static function DiscoveryRule() { $api = new self(); $api->kind = 'rules'; return $api; }
	public static function Trigger() { $api = new self(); $api->kind = 'triggers'; return $api; }
	public static function Problem() { $api = new self(); $api->kind = 'problems'; return $api; }
	public function get($options) {
		self::$calls[] = ['kind' => $this->kind, 'options' => $options];
		if ($this->kind === 'problems') {
			if ($options['objectids'] !== ['900']) { throw new RuntimeException('Events must be scoped to switch triggers.'); }
			return self::$problems;
		}
		if ($options['hostids'] !== ['42'] || !$options['monitored']) {
			throw new RuntimeException('Queries must remain scoped to the selected monitored vCenter.');
		}
		if ($this->kind === 'triggers' && ($options['tags'][0]['value'] ?? '') !== 'uuid-1') {
			throw new RuntimeException('Problems must filter the selected switch UUID.');
		}
		$keys = $options['filter']['key_'] ?? [];
		$keys = is_array($keys) ? $keys : [$keys];
		if ($this->kind === 'items' && array_intersect($keys, [
			\Modules\VMwareMonitoring\Includes\VMwareDvswitch::VM_INVENTORY_KEY,
			\Modules\VMwareMonitoring\Includes\VMwareDvswitch::HOST_INVENTORY_KEY])) {
			return array_merge(self::$vm_inventory, self::$host_inventory);
		}
		return self::${$this->kind};
	}
}
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$_GET = ['action' => 'vmware.monitoring.tab', 'hostid' => '42', 'tab' => 'dvswitches'];
$page = Collector::distributedSwitches('42', 1, 25);
check(!$page['enabled'], 'Absent companion stays optional');
check(str_contains(Renderer::render('dvswitches', $page), 'Optional monitoring'), 'Explains opt-in');
API::$rules = [['itemid' => '1', 'state' => '0', 'error' => '']];
$id = 100;
foreach (['uuid-1' => '<script>switch</script>', 'uuid-2' => 'Other switch'] as $uuid => $name) {
	foreach (['raw', 'summary', 'ports', 'connected', 'link_up', 'connected_down', 'blocked', 'conflicts'] as $field) {
		API::$items[] = ['itemid' => (string) ++$id, 'key_' => 'vmware.dvswitch.'.$field,
			'lastclock' => time(), 'lastvalue' => '0', 'state' => '0', 'error' => '', 'tags' => [
				['tag' => 'dvs.uuid', 'value' => $uuid], ['tag' => 'dvs.name', 'value' => $name],
				['tag' => 'dvs.metric', 'value' => $field]
			]];
	}
}
$page = Collector::distributedSwitches('42', 1, 1);
check($page['total'] === 2 && $page['pages'] === 2 && count($page['rows']) === 1, 'Paginates switch identities');
$html = Renderer::render('dvswitches', $page);
check(!str_contains($html, '<script>'), 'Escapes switch names');
check(substr_count($html, 'data-menu-popup=') === 7, 'Source JSON and six counts retain item actions');
check(str_contains($html, 'Collected'), 'Valid zero is collected');
check(Collector::distributedSwitches('42', 1, 25, 'UUID-2')['total'] === 1, 'Searches UUID without case sensitivity');
API::$items[1]['state'] = '1';
API::$items[1]['error'] = '<script>bad payload</script>';
$html = Renderer::render('dvswitches', Collector::distributedSwitches('42', 1, 25));
check(str_contains($html, 'Collection error') && !str_contains($html, '<script>'), 'Summary failures are visible and escaped');
API::$items[2]['lastclock'] = 0;
check(Collector::distributedSwitches('42', 1, 25)['rows']['uuid-1']['ports'] === null, 'Never sampled counts remain unknown');
API::$rules[0]['error'] = 'Discovery failed';
check(str_contains(Renderer::render('dvswitches', Collector::distributedSwitches('42', 1, 25)), 'Discovery failed'),
	'Displays discovery errors');
echo "Distributed-switch collector and renderer checks passed.\n";
