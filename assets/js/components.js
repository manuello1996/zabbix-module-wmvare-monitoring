/* Shared component behavior. Fetching, permissions and domain sorting remain with callers. */
(() => {
	const SortableHeader = {
		key(event, activate) {
			if (event.isComposing || !['Enter', ' '].includes(event.key)) return false;
			event.preventDefault();
			activate();
			return true;
		},
		bind(headers, activate) {
			for (const header of headers) {
				header.addEventListener('click', () => activate(header));
				header.addEventListener('keydown', event => this.key(event, () => activate(header)));
			}
		},
		toggle(current, direction, field) {
			return {field, direction: current === field ? -direction : 1};
		},
		update(headers, field, direction, attribute = 'sort') {
			for (const header of headers) {
				const arrow = header.querySelector('.vmware-monitoring-sort-arrow');
				const column = header.closest('th');
				if (arrow) arrow.replaceChildren();
				if (field !== null && header.dataset[attribute] === field) {
					if (arrow) {
						const icon = document.createElement('span');
						icon.className = direction === 1 ? 'arrow-up' : 'arrow-down';
						arrow.append(icon);
					}
					column?.setAttribute('aria-sort', direction === 1 ? 'ascending' : 'descending');
				}
				else column?.removeAttribute('aria-sort');
			}
		}
	};

	const Pager = {
		state(total, perPage, page) {
			const integer = (value, fallback) => Number.isFinite(Number(value)) ? Math.floor(Number(value)) : fallback;
			total = Math.max(0, integer(total, 0));
			perPage = Math.max(1, integer(perPage, 1));
			const pages = Math.max(1, Math.ceil(total / perPage));
			page = Math.min(pages, Math.max(1, integer(page, 1)));
			const start = (page - 1) * perPage;
			return {total, perPage, pages, page, start, end: Math.min(total, start + perPage)};
		},
		slice(rows, page, perPage) {
			const state = this.state(rows.length, perPage, page);
			return {...state, rows: rows.slice(state.start, state.end)};
		},
		bind({previous, next}, step) {
			previous?.addEventListener('click', () => step(-1));
			next?.addEventListener('click', () => step(1));
		},
		update({previous, next, info}, state, of = 'of') {
			if (info) info.textContent = state.total > 0 ? `${state.start + 1}-${state.end} ${of} ${state.total}` : `0 ${of} 0`;
			if (previous) previous.disabled = state.page <= 1;
			if (next) next.disabled = state.page >= state.pages;
		}
	};

	const ProblemBadges = {
		render(target, badges, {available = true, emptyText = 'None', unavailableText = 'Unavailable'} = {}) {
			target.replaceChildren();
			target.removeAttribute('aria-label');
			if (!available || !badges.length) {
				const message = document.createElement('span');
				message.textContent = available ? emptyText : unavailableText;
				if (available) message.className = 'green';
				target.append(message);
				return;
			}
			for (const badge of badges) {
				const span = document.createElement('span');
				span.className = 'problem-icon-list-item ' + badge.style;
				span.title = badge.title;
				span.textContent = badge.count;
				target.append(span);
			}
		}
	};

	const Sparkline = {
		draw(target, points, options = {}) {
			const {width = 100, height = 24, pad = 3, singlePoint = 'dot',
				disabled = false, maxPoints = 0, emptyLabel = 'No history data',
				format = value => `${value.toFixed(1)}%`, labels = {min: 'Minimum', current: 'Current', max: 'Maximum'}} = options;
			let history = Array.isArray(points) ? points.filter(point => Array.isArray(point)
				&& point.length > 1 && point[1] !== null && point[1] !== '' && Number.isFinite(Number(point[1]))) : [];
			if (maxPoints > 0 && history.length > maxPoints) {
				const count = Math.max(2, Math.floor(maxPoints));
				history = Array.from({length: count}, (_point, index) =>
					history[Math.round(index * (history.length - 1) / (count - 1))]);
			}
			const node = name => document.createElementNS('http://www.w3.org/2000/svg', name);
			const svg = node('svg');
			svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
			svg.setAttribute('preserveAspectRatio', 'none');
			svg.classList.add('vmware-monitoring-spark-svg');
			if (!history.length || disabled || (history.length === 1 && singlePoint === 'flat')) {
				target.setAttribute('aria-label', emptyLabel);
				const line = node('line');
				for (const [name, value] of Object.entries({x1: 0, y1: height - pad - 2, x2: width, y2: height - pad - 2})) {
					line.setAttribute(name, String(value));
				}
				line.classList.add('vmware-monitoring-spark-flat');
				svg.append(line);
			}
			else {
				const values = history.map(point => Number(point[1]));
				const min = Math.min(...values), max = Math.max(...values), current = values.at(-1);
				const label = `${labels.min}: ${format(min)}, ${labels.current}: ${format(current)}, ${labels.max}: ${format(max)}`;
				target.setAttribute('aria-label', label);
				const title = node('title'); title.textContent = label; svg.append(title);
				const coordinates = values.map((value, index) => {
					const x = values.length > 1 ? index / (values.length - 1) * width : width;
					const fraction = Math.max(0, Math.min(100, value)) / 100;
					return `${x.toFixed(1)},${(height - pad - fraction * (height - 2 * pad)).toFixed(1)}`;
				});
				if (values.length === 1) {
					const circle = node('circle'); const [x, y] = coordinates[0].split(',');
					circle.setAttribute('cx', x); circle.setAttribute('cy', y); circle.setAttribute('r', '2');
					circle.classList.add('vmware-monitoring-spark-point'); svg.append(circle);
				}
				else {
					const fill = node('polygon');
					fill.setAttribute('points', `0,${height - pad} ${coordinates.join(' ')} ${width},${height - pad}`);
					fill.classList.add('vmware-monitoring-spark-fill'); svg.append(fill);
					const line = node('polyline'); line.setAttribute('points', coordinates.join(' '));
					line.setAttribute('fill', 'none'); line.classList.add('vmware-monitoring-spark-line'); svg.append(line);
				}
			}
			target.replaceChildren(svg);
		}
	};

	const RowDetails = {
		setExpanded(button, panel, expanded) {
			if (!button || !panel) return false;
			button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			panel.hidden = !expanded;
			button.closest('tr')?.classList.toggle('vmware-monitoring-resource-expanded', expanded);
			return expanded;
		},
		toggle(button, panel) {
			return this.setExpanded(button, panel, button.getAttribute('aria-expanded') !== 'true');
		},
		bind(root) {
			root.addEventListener('click', event => {
				const button = event.target.closest?.('.vmware-monitoring-metrics-toggle[data-module-details-toggle]');
				if (!button || (root.contains && !root.contains(button))) return;
				const panel = document.getElementById(button.getAttribute('aria-controls'));
				if (panel) this.toggle(button, panel);
			});
		}
	};

	class AutoRefresh {
		constructor({control, interval = control?.dataset?.refreshInterval ?? 60, status = null,
			onRefresh, isBusy = () => false, onChange = () => {}}) {
			this.control = control;
			this.status = status;
			const seconds = Number(interval);
			this.interval = Math.max(10, Number.isFinite(seconds) && seconds !== 0 ? seconds : 60);
			this.onRefresh = onRefresh;
			this.isBusy = isBusy;
			this.onChange = onChange;
			this.enabled = false;
			this.running = false;
			this.suspended = false;
			this.timer = null;
			this.lastUpdated = null;
			this.message = '';
			const data = control?.dataset ?? {};
			this.labels = {updatedAt: data.updatedAt ?? 'Updated at %1$s',
				enabled: data.autoRefreshEnabled ?? 'Auto-refresh enabled',
				error: data.refreshError ?? 'Unable to refresh data. Showing the last loaded values.'};
			this.changeHandler = () => this.setEnabled(Boolean(control.checked));
			if (control) {
				control.checked = false;
				control.addEventListener('change', this.changeHandler);
			}
		}

		cancel() {
			clearTimeout(this.timer);
			this.timer = null;
		}
		get cacheLifetime() {
			return Math.min(this.enabled ? this.interval : 60, 60) * 1000;
		}
		pause() {
			this.suspended = true;
			this.cancel();
		}
		resume() {
			this.suspended = false;
			this.schedule();
		}
		setEnabled(enabled) {
			this.enabled = enabled;
			if (this.control) this.control.checked = enabled;
			this.cancel();
			this.onChange(enabled);
			this.renderStatus();
			this.schedule();
		}
		schedule() {
			this.cancel();
			if (!this.enabled || this.suspended || this.running) return;
			this.timer = setTimeout(async () => {
				this.timer = null;
				if (!this.enabled || this.suspended) return;
				if (document.hidden || (typeof overlays_stack !== 'undefined' && overlays_stack.length) || this.isBusy()) {
					this.schedule();
					return;
				}
				this.running = true;
				try { await this.onRefresh(); }
				catch (_error) { this.setStatus(this.labels.error); }
				finally { this.running = false; this.schedule(); }
			}, this.interval * 1000);
		}
		updated(at = Date.now()) {
			this.lastUpdated = at;
			this.message = '';
			this.renderStatus();
		}
		completedStatus() {
			const updated = this.lastUpdated !== null
				? this.labels.updatedAt.replace('%1$s', new Date(this.lastUpdated).toLocaleTimeString()) : '';
			return [updated, this.enabled ? this.labels.enabled : ''].filter(Boolean).join(' · ');
		}
		setStatus(message) {
			this.message = message;
			this.renderStatus();
		}
		renderStatus() {
			if (this.status) this.status.textContent = this.message || this.completedStatus();
		}
		destroy() {
			this.enabled = false;
			this.pause();
			this.control?.removeEventListener('change', this.changeHandler);
		}
	}

	Object.assign(window.VMwareCommon, {SortableHeader, Pager, ProblemBadges, Sparkline, RowDetails, AutoRefresh});
})();
