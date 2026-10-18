import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../views/js/vmware.monitoring.dvswitch.js.php', import.meta.url), 'utf8')
	.replace(/^<\?php.*?\?>\s*<script>\s*/, '').replace(/<\/script>\s*$/, '')
	.replace(/<\?= json_encode\(_\('([^']+)'\)\) \?>/g, (_, text) => JSON.stringify(text));
const handlers = {};
const first = {hidden: true}, second = {hidden: true};
const rows = {first, second};
const context = {document: {
	addEventListener(type, fn) {handlers[type] = fn;}, getElementById(id) {return rows[id] ?? null;}
}};
vm.runInNewContext(source, context);
const button = {
	dataset: {vmwareDvsPortToggle: 'first'}, attributes: {'aria-expanded': 'false'},
	getAttribute(name) {return this.attributes[name];},
	setAttribute(name, value) {this.attributes[name] = value;}
};
handlers.click({target: {closest: () => button}});
assert.equal(first.hidden, false);
assert.equal(second.hidden, true, 'Expanding one port leaves other rows collapsed');
assert.equal(button.attributes['aria-expanded'], 'true');
assert.equal(button.textContent, 'Hide details');
handlers.click({target: {closest: () => button}});
assert.equal(first.hidden, true);
assert.equal(button.attributes['aria-expanded'], 'false');
assert.equal(button.textContent, 'Port details');
button.dataset.vmwareDvsPortToggle = 'missing';
handlers.click({target: {closest: () => button}});
handlers.click({target: {closest: () => null}});
console.log('Port expansion checks passed: full-row toggle, accessible state, independent panels and missing rows.');
let focused = false, scrolled = false;
first.id = 'first';
first.scrollIntoView = () => {scrolled = true;};
button.focus = () => {focused = true;};
context.document.querySelector = () => button;
const port = {dataset: {vmwareDvsPortOpen: 'first'}};
handlers.click({target: {closest: selector => selector.includes('port-open') ? port : null}});
assert.equal(first.hidden, false, 'Panel click opens its matching detail row');
assert.equal(button.attributes['aria-expanded'], 'true');
assert.equal(focused && scrolled, true, 'Panel selection moves focus and scrolls to details');
assert.equal(second.hidden, true);
console.log('Switch panel selection opens the matching row and updates its accessible toggle.');
