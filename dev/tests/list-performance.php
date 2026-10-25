<?php
/** php dev/tests/list-performance.php /usr/share/zabbix */
namespace Modules\VMwareMonitoring\Includes {
	class ListCacheFixture { public static string $directory; public static int $now; }
	function sys_get_temp_dir() { return ListCacheFixture::$directory; }
	function time() { return ListCacheFixture::$now; }
}
namespace {
	require ($argv[1] ?? '/usr/share/zabbix').'/include/defines.inc.php';
	function timeUnitToSeconds($value) { return 86400; }
	class CController {
		public array $input = [];
		public $response;
		protected function getInput($key, $default = null) { return $this->input[$key] ?? $default; }
		protected function hasInput($key) { return array_key_exists($key, $this->input); }
		protected function setResponse($response) { $this->response = $response; }
	}
	class CControllerResponseData {
		public function __construct(public array $data) {}
		public function setTitle($title) {}
	}
	class CWebUser {
		public static array $data = ['userid' => '1', 'sessionid' => 'list-fixture'];
		public static function getRefresh() { return '1m'; }
	}
	class CSessionHelper {
		public static array $values = [];
		public static function get($key) { return self::$values[$key] ?? null; }
		public static function set($key, $value) { self::$values[$key] = $value; }
		public static function unset($keys) { foreach ($keys as $key) { unset(self::$values[$key]); } }
	}
	class CProfile {
		public static array $values = [];
		public static function getArray($key, $default) { return self::$values[$key] ?? $default; }
	}
	class CSettingsHelper {
		const HISTORY_PERIOD = 'history_period';
		public static function get($key) { return '1d'; }
	}
	class DB {
		public static array $prototypes = [], $discovered = [], $calls = [];
		public static function select($table, $options) {
			self::$calls[] = $options;
			return isset($options['filter']['parent_itemid']) ? self::$prototypes : self::$discovered;
		}
	}
	class API {
		public static array $hosts = [], $rules = [], $items = [], $problems = [], $triggers = [], $calls = [];
		public static bool $shell = false;
		public static function Host() { return new self('hosts'); }
		public static function HostGroup() { return new self('groups'); }
		public static function DiscoveryRule() { return new self('rules'); }
		public static function Item() { return new self('items'); }
		public static function Problem() { return new self('problems'); }
		public static function Trigger() { return new self('triggers'); }
		public function __construct(private string $kind) {}
		public function get($options) {
			self::$calls[] = [$this->kind, $options];
			if (self::$shell && !in_array($this->kind, ['hosts', 'groups'])) {
				throw new \RuntimeException('The initial page must never query monitoring data.');
			}
			if ($this->kind === 'hosts') {
				return array_intersect_key(self::$hosts, array_flip($options['hostids']));
			}
			if ($this->kind === 'groups') { return []; }
			if ($this->kind === 'rules') { return self::$rules; }
			if ($this->kind === 'items') {
				return array_values(array_filter(self::$items, static function ($item) use ($options) {
					if (!in_array($item['hostid'], $options['hostids'])) { return false; }
					if (isset($options['search'])) { return str_starts_with($item['key_'], $options['search']['key_']); }
					return in_array($item['key_'], (array) $options['filter']['key_']);
				}));
			}
			if ($this->kind === 'problems') {
				check($options['suppressed'] === false && $options['symptom'] === false, 'Preserve problem exclusions');
				check(isset($options['hostids']) && !isset($options['objectids']), 'Problems are queried before triggers');
				return self::$problems;
			}
			check(isset($options['triggerids']) && count($options['triggerids']) <= count(self::$problems),
				'Only fetch triggers referenced by open problems, never every trigger on 2400 hosts');
			check($options['monitored'] && $options['skipDependent'], 'Preserve monitored/dependency filtering');
			return array_intersect_key(self::$triggers, array_flip($options['triggerids']));
		}
	}
	require __DIR__.'/../../includes/VMwareDataCache.php';
	require __DIR__.'/../../includes/VMwareCollector.php';
	require __DIR__.'/../../includes/VMwareVCenterSort.php';
	require __DIR__.'/../../actions/CControllerVMwareList.php';
	use Modules\VMwareMonitoring\Includes\VMwareCollector as Collector;
	use Modules\VMwareMonitoring\Includes\ListCacheFixture;
	class ListShell extends Modules\VMwareMonitoring\Actions\CControllerVMwareList {
		public function runFixture() { $this->doAction(); }
	}
	function check($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } }
	ListCacheFixture::$directory = sys_get_temp_dir().'/vmware-list-test-'.bin2hex(random_bytes(8));
	ListCacheFixture::$now = time();
	mkdir(ListCacheFixture::$directory, 0700);
	try {
		API::$shell = true;
		$shell = new ListShell();
		$shell->input = ['force_vcenter_refresh' => '1'];
		$shell->runFixture();
		check(API::$calls === [], 'An unfiltered page shell makes zero monitoring API calls, even on refresh');
		check(!isset($shell->response->data['totals']), 'Do not render missing measurements as zero');
		API::$shell = false;
		$vcenters = [];
		for ($i = 1; $i <= 17; $i++) {
			$id = (string) $i;
			$vcenters[$id] = ['hostid' => $id, 'name' => 'VC '.$i];
			foreach (['hv', 'vm'] as $kind) {
				$rule = 'rule-'.$kind.'-'.$i;
				API::$rules[$rule] = ['itemid' => $rule, 'hostid' => $id,
					'key_' => 'vmware.'.$kind.'.discovery[{$VMWARE.URL}]'];
				DB::$prototypes[] = ['hostid' => 'proto-'.$kind.'-'.$i, 'parent_itemid' => $rule];
			}
			foreach (Collector::VCENTER_KEYS as $key => $field) {
				API::$items[] = ['itemid' => count(API::$items) + 1, 'hostid' => $id, 'key_' => $key,
					'lastclock' => time(), 'lastvalue' => '1'];
			}
		}
		for ($i = 0; $i < 2383; $i++) {
			$id = (string) (1000 + $i); $vc = $i % 17 + 1; $kind = $i < 250 ? 'hv' : 'vm';
			API::$hosts[$id] = ['hostid' => $id, 'name' => $kind.' '.$i];
			DB::$discovered[] = ['hostid' => $id, 'parent_hostid' => 'proto-'.$kind.'-'.$vc];
			if ($kind === 'hv') {
				API::$items[] = ['itemid' => count(API::$items) + 1, 'hostid' => $id,
					'key_' => 'vmware.hv.vm.num[{$VMWARE.URL},{$VMWARE.HV.UUID}]',
					'value_type' => ITEM_VALUE_TYPE_UINT64, 'lastclock' => time(), 'lastvalue' => '10'];
				API::$items[] = ['itemid' => count(API::$items) + 1, 'hostid' => $id,
					'key_' => 'vmware.hv.datastore.size[url,hv,ds-'.$vc.',total]', 'name' => 'Datastore',
					'tags' => [['tag' => 'datastore', 'value' => 'Shared DS']], 'lastclock' => time(), 'lastvalue' => '100'];
			}
		}
		API::$problems = [
			['eventid' => '5000', 'objectid' => '900', 'severity' => '4'],
			['eventid' => '5001', 'objectid' => '901', 'severity' => '3']
		];
		// Both referenced hosts belong to vCenter 1; the second trigger is filtered as dependent/disabled.
		API::$triggers = ['900' => ['hosts' => [['hostid' => '1000'], ['hostid' => '1255']]]];
		$result = Collector::collectVCenterMetrics($vcenters);
		check(count($result) === 17, 'All vCenters are retained');
		check(array_sum(array_column($result, 'hypervisors')) === 250, 'All hypervisors are counted');
		check(array_sum(array_column($result, 'vms')) === 2500, 'Keep reported VM totals distinct from discovered hosts');
		check(array_sum(array_column($result, 'datastores')) === 17, 'Shared datastore attachments are deduplicated');
		check($result[1]['problems'] === [4 => 1], 'Shared problem events count once per environment');
		check(count(API::$calls) <= 8 && count(DB::$calls) === 2, 'Queries are batched across 17 vCenters');
		$cold_calls = count(API::$calls);
		check(Collector::collectVCenterMetrics(array_reverse($vcenters, true)) === $result, 'Cache keys ignore host ordering');
		check(count(API::$calls) === $cold_calls, 'Warm list metrics require zero additional API queries');
		ListCacheFixture::$now += 61;
		Collector::collectVCenterMetrics($vcenters);
		check(count(API::$calls) === 2 * $cold_calls, 'Operational metrics expire after 60 seconds');
		Collector::clearSessionDataCache();
		Collector::collectVCenterMetrics($vcenters);
		check(count(API::$calls) === 3 * $cold_calls, 'Manual refresh bypasses the server summary cache');
		CWebUser::$data['userid'] = '2';
		Collector::collectVCenterMetrics($vcenters);
		check(count(API::$calls) === 4 * $cold_calls, 'Metrics remain isolated per user');
		API::$calls = []; API::$problems = [];
		check(Collector::problemsByHosts(['1000']) === [], 'No problems remain empty');
		check(count(API::$calls) === 1, 'Healthy environments do not need a trigger query');
		check(Collector::collectVCenterMetrics([]) === [], 'Empty scope stays empty');
		echo "2400-host fixture: shell 0 monitoring queries; cold inventory $cold_calls batched API queries; warm inventory 0.\n";
	}
	finally {
		foreach (glob(ListCacheFixture::$directory.'/*/*') as $file) { unlink($file); }
		foreach (glob(ListCacheFixture::$directory.'/*') as $directory) { rmdir($directory); }
		rmdir(ListCacheFixture::$directory);
	}
}
