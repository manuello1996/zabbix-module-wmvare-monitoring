<?php
/** php dev/tests/list-rendering.php /usr/share/zabbix (native HTML classes, no database). */
$ui = $argv[1] ?? '/usr/share/zabbix';
function _s($text, ...$args) { return sprintf($text, ...$args); }
function _x($text, $context) { return $text; }
function _n($one, $many, $count) { return $count === 1 ? $one : $many; }
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
CView::registerDirectory(dirname(__DIR__, 2).'/views');
use Modules\VMwareMonitoring\Includes\VMwareItemMenu;
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$_GET = ['action' => 'vmware.monitoring.list.data', 'filter_hostids' => ['42'], 'filter_groupids' => ['7'],
	'tab' => 'vcenters', 'page' => '2', 'force_vcenter_refresh' => '1'];
$html = (string) VMwareItemMenu::link('value', '100');
preg_match('/data-menu-popup="([^"]+)"/', $html, $match);
$menu = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
parse_str(parse_url($menu['data']['backurl'], PHP_URL_QUERY), $args);
check($args['action'] === 'vmware.monitoring.list', 'Native item actions return to the normal page');
check($args['filter_hostids'] === ['42'] && $args['filter_groupids'] === ['7'] && $args['filter_set'] === '1',
	'Item menus preserve the original filter scope');
check($args['page'] === '2' && !isset($args['force_vcenter_refresh']), 'Back links retain pagination without repeating cache invalidation');
$summary = (new CView('vmware.monitoring.list.summary', ['totals' => [
	'vcenters' => 17, 'hypervisors' => 250, 'vms' => 2133, 'datastores' => 12
]]))->getOutput();
check(str_contains($summary, '>250<') && !str_contains($summary, '<script'), 'Summary fragments contain metrics without page scripts');
$data = ['tab' => 'vcenters', 'vcenters' => [[
	'hostid' => '42', 'name' => '<script>vCenter</script>', 'location_path' => [],
	'metrics' => ['health' => 0, 'health_itemid' => '100', 'version' => '8', 'version_itemid' => '101'],
	'problems' => [], 'problem_hostids' => ['42'], 'hypervisors' => 250, 'vms' => 2133,
	'datastores' => 12, 'inventory' => ['notes' => '<script>notes</script>']
]], 'location_groups' => [], 'paging' => null];
$content = (new CView('vmware.monitoring.list.content', $data))->getOutput();
check(str_contains($content, 'vmware-monitoring-vcenters-table'), 'JSON content retains the native inventory table');
check(!str_contains($content, '<script') && str_contains($content, '&lt;script&gt;notes'), 'Fragments escape inventory text and contain no executable scripts');
check(substr_count($content, 'data-menu-popup=') === 2, 'Health and version keep their native item actions');
foreach (['vcenter', 'health', 'problems', 'version', 'hypervisors', 'vms', 'datastores', 'notes'] as $field) {
	check(str_contains($content, 'sort='.$field.'&amp;sortorder='), "The $field header has an asynchronous sort link");
}
$data['sort'] = 'hypervisors'; $data['sortorder'] = ZBX_SORT_DOWN;
$content = (new CView('vmware.monitoring.list.content', $data))->getOutput();
check(str_contains($content, 'arrow-down') && str_contains($content, 'sort=hypervisors&amp;sortorder=ASC'),
	'Descending headers indicate the selected direction and toggle back to ascending');
$data['vcenters'] = [];
check(str_contains((new CView('vmware.monitoring.list.content', $data))->getOutput(), 'No vCenter hosts found'), 'Empty scopes render the empty inventory');
echo "List fragments, escaping and native item-menu return paths passed.\n";
