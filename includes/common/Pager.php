<?php declare(strict_types = 0);

namespace Modules\VMwareMonitoring\Includes\Common;

/** Native pagination plus controls for inventories updated without page navigation. */
class Pager extends \CPagerHelper {
	public static function forTotal(int $page, int $total, int $per_page, \CUrl $url,
			bool $limit_exceeded = false): \CTag {
		$per_page = max(1, $per_page);
		$total = max(0, $total);
		$pages = max(1, (int) ceil($total / $per_page));
		return parent::render(min(max(1, $page), $pages), $total, $pages, $url, $limit_exceeded, $per_page);
	}

	public static function controls(string $previous_id, string $info_id, string $next_id): \CDiv {
		return (new \CDiv([
			(new \CTag('button', true, '‹'))->setAttribute('type', 'button')->setId($previous_id)
				->addClass('vmware-monitoring-pager-btn')->setAttribute('aria-label', _('Previous page')),
			(new \CSpan())->setId($info_id)->addClass('vmware-monitoring-pager-info')->setAttribute('aria-live', 'polite'),
			(new \CTag('button', true, '›'))->setAttribute('type', 'button')->setId($next_id)
				->addClass('vmware-monitoring-pager-btn')->setAttribute('aria-label', _('Next page'))
		]))->addClass('vmware-monitoring-pager');
	}

	public static function ajax(array $data, string $attribute): \CDiv {
		$total = max(0, (int) $data['total']);
		$per_page = max(1, (int) $data['per_page']);
		$pages = max(1, (int) ceil($total / $per_page));
		$page = min($pages, max(1, (int) $data['page']));
		$start = ($page - 1) * $per_page;
		$links = [];
		$link = static fn($content, int $target, string $label): \CLink =>
			(new \CLink($content, '#'))->setAttribute($attribute, (string) $target)->setAttribute('aria-label', $label);
		if ($pages > 1) {
			$end_page = min($pages, max(11, $page + 5));
			$start_page = max(1, $end_page - 10);
			if ($start_page > 1) { $links[] = $link(_x('First', 'page navigation'), 1, _('Go to first page')); }
			if ($page > 1) { $links[] = $link((new \CSpan())->addClass(ZBX_STYLE_ARROW_LEFT), $page - 1,
				_s('Go to previous page, %1$s', $page - 1)); }
			for ($number = $start_page; $number <= $end_page; $number++) {
				$item = $link((string) $number, $number, $number === $page
					? _s('Go to page %1$s, current page', $number) : _s('Go to page %1$s', $number));
				if ($number === $page) { $item->addClass(ZBX_STYLE_PAGING_SELECTED)->setAttribute('aria-current', 'true'); }
				$links[] = $item;
			}
			if ($page < $pages) { $links[] = $link((new \CSpan())->addClass(ZBX_STYLE_ARROW_RIGHT), $page + 1,
				_s('Go to next page, %1$s', $page + 1)); }
			if ($end_page < $pages) { $links[] = $link(_x('Last', 'page navigation'), $pages, _s('Go to last page, %1$s', $pages)); }
		}
		$stats = $pages === 1 ? _s('Displaying %1$s of %2$s found', $total, $total)
			: _s('Displaying %1$s to %2$s of %3$s found', $start + 1, min($total, $start + $per_page), $total);
		return (new \CDiv((new \CTag('nav', true, [$links, (new \CDiv($stats))->addClass(ZBX_STYLE_TABLE_STATS)]))
			->addClass(ZBX_STYLE_PAGING_BTN_CONTAINER)->setAttribute('role', 'navigation')
			->setAttribute('aria-label', _x('Pager', 'page navigation'))))->addClass(ZBX_STYLE_TABLE_PAGING);
	}
}
