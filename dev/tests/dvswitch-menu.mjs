// node dev/tests/dvswitch-menu.mjs /path/to/Zabbix/ui/js/menupopup.js
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../views/vmware.monitoring.dvswitch.php', import.meta.url), 'utf8');
assert.ok(view.includes("includeJsFile('vmware.monitoring.hostmenu.js.php')"),
	'Switch pages must load the native dialog/menu adapter');
const native = readFileSync(process.argv[2], 'utf8');
const start = native.indexOf('function getMenuPopupItem(options)');
const end = native.indexOf('function getMenuPopupItemPrototype(options)');
assert.ok(start >= 0 && end > start);
const adapter = readFileSync(new URL('../../views/js/vmware.monitoring.hostmenu.js.php', import.meta.url), 'utf8')
	.replace(/^<\?php.*?\?>\s*<script>\s*/, '').replace(/<\/script>\s*$/, '')
	.replace(/<\?= [\s\S]*?\?>/g, '"fixture"');
const pageUrl = 'http://127.0.0.1/zabbix.php?action=vmware.monitoring.dvswitch&hostid=10735&uuid=switch&section=ports&search=&state=all&portgroup=&resource=&apply=Apply';
const calls = [], handlers = {};
let restored, reloads = 0;
class Curl {
	constructor(path) { this.url = new URL(path, pageUrl); }
	setArgument(key, value) { this.url.searchParams.set(key, value); }
	getUrl() { return this.url.toString(); }
}
const context = {
	URLSearchParams, Curl, t: value => value,
	document: {querySelectorAll() { return [{getAttribute() { return JSON.stringify({type: 'item', data: {itemid: '53977', backurl: pageUrl}}); }}]; }, addEventListener() {}}, location: {href: pageUrl, reload() { reloads++; }},
	history: {replaceState(_state, _title, url) { restored = url; }},
	clearMessages() {}, postMessageOk() {}, postMessageDetails() {},
	PopUp(action, data, options) {
		calls.push({action, data, options});
		return {$dialogue: [{addEventListener(type, fn) {handlers[type] = fn;}}]};
	}
};
context.window = context;
vm.createContext(context);
vm.runInContext(native.slice(start, end), context);
for (const file of ['common.js', 'components.js']) vm.runInContext(readFileSync(new URL('../../assets/js/' + file, import.meta.url), 'utf8'), context);
vm.runInContext(adapter, context);
for (const method of ['editHost', 'editItem', 'editTrigger']) {
	assert.equal(typeof context.view[method], 'function', `${method} is available on switch pages`);
}
const options = {itemid: '53977', hostid: '10735', name: 'Get ports', context: 'host',
	backurl: pageUrl, showGraph: false, history: true, trends: false,
	allowed_ui_latest_data: true, allowed_ui_conf_hosts: true, isWriteable: true,
	triggers: [], isExecutable: true};
const entries = opts => context.getMenuPopupItem(opts).flatMap(section => section.items);
const menu = entries(options);
assert.equal(menu.map(item => item.label).join('|'), 'Latest data|Graph|Values|500 latest values|Item|Host|Triggers|Execute now');
assert.equal(menu.find(item => item.label === 'Graph').disabled, true);
assert.equal(entries({...options, showGraph: true}).find(item => item.label === 'Graph').disabled, false);
assert.equal(entries({...options, isWriteable: false, allowed_ui_conf_hosts: false}).some(item => item.label === 'Item'), false);
for (const entry of menu.filter(item => item.url)) {
	assert.equal(new URL(entry.url, pageUrl).origin, 'http://127.0.0.1');
	assert.equal(new URL(entry.url, pageUrl).protocol, 'http:');
}
menu.find(item => item.label === 'Item').clickCallback();
assert.equal(calls.at(-1).action, 'item.edit');
assert.equal(calls.at(-1).data.itemid, '53977');
handlers['dialogue.close']();
assert.equal(restored, pageUrl, 'Closing the dialog preserves the complete HTTP return URL');
handlers['dialogue.submit']({detail: {success: {title: 'Saved'}}});
assert.equal(reloads, 1);
context.view.editHost('10735');
assert.equal(calls.at(-1).action, 'popup.host.edit');
assert.equal(calls.at(-1).data.hostid, '10735');
handlers['dialogue.close']();
assert.equal(restored, pageUrl);
console.log('Switch menu/dialog regression checks passed: callbacks, permissions, item actions, and HTTP return URLs.');
