import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source = (await readFile(new URL('../../views/js/vmware.monitoring.list.js.php', import.meta.url), 'utf8'))
	.replace(/^<\?php.*?\?>\s*<script>\s*/, '').replace(/<\/script>\s*$/, '')
	.replace(/<\?= json_encode\(_\('([^']+)'\)\) \?>/g, (_match, text) => JSON.stringify(text))
	.replace(/<\?= [\s\S]*?\?>/g, '"fixture"');
const element = () => ({
	innerHTML: '', attributes: {}, listeners: {}, textContent: '',
	setAttribute(name, value) {this.attributes[name] = value;},
	addEventListener(name, callback) {this.listeners[name] = callback;},
	querySelectorAll() {return [];}, contains() {return true;},
	replaceChildren(child) {this.innerHTML = child?.textContent || '';}
});
const content = element(), summary = element(), refresh = element(), status = element();
const refreshButton = element(), autoRefresh = element();
autoRefresh.checked = true; // A restored browser form must not enable polling by default.
const tabs = ['vcenters', 'issues', 'problems'].map(key => ({
	...element(), dataset: {vmwareMonitoringListTab: key}, classList: {toggle() {}},
	href: `http://localhost/zabbix.php?action=vmware.monitoring.list&tab=${key}`
}));
const requests = [], timers = new Map(), history = [], events = [];
let now = 1000, timerId = 0;
const elements = new Map([
	['vmware-monitoring-tab-content', content], ['vmware-monitoring-list-summary', summary],
	['vmware-monitoring-refresh-vcenters', refresh], ['vmware-monitoring-refresh-status', status],
	['vmware-monitoring-refresh-vcenters-button', refreshButton], ['vmware-monitoring-auto-refresh', autoRefresh]
]);
const window = {
	location: {href: tabs[0].href + '&force_vcenter_refresh=1&filter_set=1'},
	addEventListener() {},
	history: Object.fromEntries(['pushState', 'replaceState'].map(method => [method, (_state, _unused, url) => {
		history.push([method, url]); window.location.href = url;
	}]))
};
const context = {
	window, URL, URLSearchParams, AbortController,
	DOMException: class extends Error {constructor(message, name) {super(message); this.name = name;}},
	Date: class extends Date {static now() {return now;}}, CustomEvent: class {constructor(name) {this.name = name;}},
	localStorage: {getItem: () => null, setItem() {}},
	document: {
		hidden: false, getElementById: id => elements.get(id) || null,
		querySelectorAll: () => tabs, createElement: element,
		dispatchEvent: event => events.push(event.name)
	},
	setTimeout: callback => {timers.set(++timerId, callback); return timerId;},
	clearTimeout: id => timers.delete(id),
	fetch: (url, options) => new Promise((resolve, reject) => requests.push({
		url: new URL(url), options, resolve: data => resolve({ok: true, json: async () => data}), reject
	}))
};
vm.runInNewContext(source, context);
const app = window.vmware_monitoring_list;
const settle = async () => {for (let i = 0; i < 12; i++) {await Promise.resolve();}};
const payload = name => ({content: `${name} table`, summary: `${name} totals`});
const runTimer = () => {
	assert.equal(timers.size, 1);
	const [id, callback] = [...timers][0]; timers.delete(id); callback();
};

app.init({tab: 'vcenters', refresh: 10, refresh_interval: 10, force_refresh: true,
	filter_groupids: ['7'], filter_hostids: ['42']});
assert.equal(requests.length, 1, 'Initial load requests only the selected tab');
assert.equal(requests[0].url.searchParams.get('action'), 'vmware.monitoring.list.data');
assert.equal(requests[0].url.searchParams.get('filter_groupids[]'), '7');
assert.equal(requests[0].url.searchParams.get('filter_hostids[]'), '42');
assert.equal(requests[0].url.searchParams.get('force_vcenter_refresh'), '1');
assert.equal(new URL(window.location.href).searchParams.has('force_vcenter_refresh'), false);
assert.equal(summary.innerHTML, '', 'No invented zero totals before data arrives');
assert.equal(content.attributes['aria-busy'], 'true');
assert.equal(timers.size, 0, 'Auto-refresh cannot overlap an initial request');
requests[0].resolve(payload('vcenters')); await settle();
assert.equal(content.innerHTML, 'vcenters table');
assert.equal(summary.innerHTML, 'vcenters totals');
assert.equal(content.attributes['aria-busy'], 'false');
assert.equal(requests.length, 1, 'Inactive tabs are not prefetched');
assert.equal(autoRefresh.checked, false);
assert.equal(app.refresh, 0, 'General user refresh preferences do not enable polling');
assert.equal(timers.size, 0, 'There is no automatic refresh timer by default');
assert.match(status.textContent, /^Updated at /);
assert.equal(status.textContent.includes('Auto-refresh enabled'), false);
autoRefresh.checked = true;
autoRefresh.listeners.change();
assert.equal(timers.size, 1);
assert.equal(status.textContent.includes('Auto-refresh enabled'), true);

// Rapid switches abort obsolete requests; late responses cannot replace or cache current data.
const firstIssues = app.activateTab('issues', {url: tabs[1].href, historyMode: 'push'});
const problems = app.activateTab('problems', {url: tabs[2].href, historyMode: 'push'});
assert.equal(requests[1].options.signal.aborted, true);
requests[2].resolve(payload('problems')); await problems;
requests[1].resolve(payload('obsolete')); await firstIssues;
assert.equal(content.innerHTML, 'problems table');
assert.equal(app.tabContent.has(app.tabCacheKey(tabs[1].href)), false);
await app.activateTab('vcenters', {url: tabs[0].href, historyMode: 'push'});
assert.equal(requests.length, 3, 'Fresh tab data is reused');
assert.equal(content.innerHTML, 'vcenters table');
now += 11000;
const stale = app.activateTab('vcenters');
assert.equal(requests.length, 4, 'Expired tabs are reloaded');
requests[3].resolve(payload('fresh')); await stale;

// Pagination and sorting stay asynchronous, keep the filter snapshot, and enter browser history.
let prevented = false;
app.navigateContent({button: 0, target: {closest: () => ({
	href: tabs[1].href + '&page=2&sort=severity&sortorder=DESC'
})}, preventDefault() {prevented = true;}});
assert.equal(prevented, true);
assert.equal(requests[4].url.searchParams.get('page'), '2');
assert.equal(requests[4].url.searchParams.get('sort'), 'severity');
assert.equal(requests[4].url.searchParams.get('filter_hostids[]'), '42');
assert.equal(new URL(window.location.href).searchParams.get('page'), '2');
requests[4].resolve(payload('page2')); await settle();

// Automatic refresh preserves the rendered table on failure and waits before retrying.
runTimer();
assert.equal(requests.length, 6);
assert.equal(requests[5].url.searchParams.has('force_vcenter_refresh'), false);
assert.equal(content.innerHTML, 'page2 table');
requests[5].reject(new Error('offline')); await settle();
assert.equal(content.innerHTML, 'page2 table');
assert.match(status.textContent, /Unable to refresh/);
assert.equal(content.attributes['aria-busy'], 'false');
assert.equal(timers.size, 1);
context.document.hidden = true; runTimer();
assert.equal(requests.length, 6, 'Hidden pages do not poll');
context.document.hidden = false;

// Manual refresh bypasses both caches; malformed/login responses cannot be cached as success.
let clickPrevented = false;
refreshButton.listeners.click({preventDefault() {clickPrevented = true;}});
assert.equal(clickPrevented, true, 'The actual submit button is handled without page navigation');
assert.equal(refreshButton.disabled, true);
assert.equal(requests[6].url.searchParams.get('force_vcenter_refresh'), '1');
assert.equal(app.tabContent.size, 0);
refresh.listeners.submit({preventDefault() {}});
assert.equal(requests.length, 7, 'A pending click cannot trigger a duplicate form request');
requests[6].resolve({error: {messages: ['No permissions']}}); await settle();
assert.equal(content.innerHTML, 'page2 table');
assert.equal(app.tabContent.size, 0);
assert.equal(refreshButton.disabled, false, 'A failed refresh re-enables the control');
refresh.listeners.submit({preventDefault() {}});
requests[7].resolve(payload('recovered')); await settle();
assert.equal(content.innerHTML, 'recovered table');
assert.equal(refreshButton.disabled, false);
assert.match(status.textContent, /^Updated at /);
autoRefresh.checked = false;
autoRefresh.listeners.change();
assert.equal(timers.size, 0, 'Disabling auto-refresh cancels the outstanding timer');
assert.equal(status.textContent.includes('Auto-refresh enabled'), false);
assert.ok(events.includes('zbx_reload'));
assert.ok(history.some(([method]) => method === 'pushState'));
console.log('Async load, direct refresh button, polling opt-in, filter scope, cancellation, sorting and recovery passed.');
