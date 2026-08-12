<?php
declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\Html;

final class AiSiteAnchorContract
{
	private const LOCAL_ANCHOR_PREFIX = 'ai-site-block-';
	private const HREF_PREFIX = '#ai-site-block-';
	private const FINAL_HREF_PREFIX = '#block';
	private const PAD_LENGTH = 2;

	public static function buildLocalAnchor(int $zeroBasedPosition): string
	{
		$order = max(1, $zeroBasedPosition + 1);

		return self::LOCAL_ANCHOR_PREFIX . str_pad((string)$order, self::PAD_LENGTH, '0', STR_PAD_LEFT);
	}

	public static function buildHref(int $zeroBasedPosition): string
	{
		return '#' . self::buildLocalAnchor($zeroBasedPosition);
	}

	public static function buildSiteMap(array $structure): array
	{
		$siteMap = [];
		foreach ($structure as $position => $spec)
		{
			if (!is_array($spec))
			{
				continue;
			}

			$zeroBasedPosition = max(0, (int)$position);
			$siteMap[] = [
				'position' => $zeroBasedPosition + 1,
				'navigation_title' => self::resolveNavigationTitle($spec, $zeroBasedPosition),
				'anchor' => self::buildHref($zeroBasedPosition),
			];
		}

		usort($siteMap, static function (array $left, array $right): int
		{
			return ((int)($left['position'] ?? 0)) <=> ((int)($right['position'] ?? 0));
		});

		return $siteMap;
	}

	public static function buildSingleEntrySiteMap(int $oneBasedPosition, string $navigationTitle): array
	{
		$oneBasedPosition = max(1, $oneBasedPosition);
		$zeroBasedPosition = $oneBasedPosition - 1;

		return [[
			'position' => $oneBasedPosition,
			'navigation_title' => self::normalizeTitle($navigationTitle, $zeroBasedPosition),
			'anchor' => self::buildHref($zeroBasedPosition),
		]];
	}

	public static function buildSiteMapFromOrderNumbers(array $orders): array
	{
		$normalizedOrders = [];
		foreach ($orders as $order)
		{
			$order = (int)$order;
			if ($order > 0)
			{
				$normalizedOrders[$order] = $order;
			}
		}
		ksort($normalizedOrders);

		$siteMap = [];
		foreach ($normalizedOrders as $order)
		{
			$siteMap[] = [
				'position' => $order,
				'navigation_title' => 'Block ' . $order,
				'anchor' => self::buildHref($order - 1),
			];
		}

		return $siteMap;
	}

	public static function encodeSiteMap(array $siteMap): string
	{
		$json = json_encode(array_values($siteMap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

		return is_string($json) && $json !== '' ? $json : '[]';
	}

	public static function normalizePlannedAnchors(
		string $html,
		array $siteMap,
		?int $currentZeroBasedPosition = null
	): string
	{
		$entriesByPosition = self::indexSiteMapByPosition($siteMap);
		if ($html === '' || $entriesByPosition === [])
		{
			return $html;
		}

		$anchorPattern = '~<a\b([^>]*?\bhref\s*=\s*)(["\']?)'
			. preg_quote(self::HREF_PREFIX, '~')
			. '(\d+)\2([^>]*)>(.*?)</a>~isu';

		$html = preg_replace_callback(
			$anchorPattern,
			static function (array $matches) use ($entriesByPosition, $currentZeroBasedPosition): string
			{
				$href = self::resolvePlannedHref(
					(int)($matches[3] ?? 0),
					(string)($matches[5] ?? ''),
					$entriesByPosition,
					$currentZeroBasedPosition,
				);

				return '<a'
					. (string)($matches[1] ?? '')
					. (string)($matches[2] ?? '')
					. $href
					. (string)($matches[2] ?? '')
					. (string)($matches[4] ?? '')
					. '>'
					. (string)($matches[5] ?? '')
					. '</a>'
				;
			},
			$html,
		) ?? $html;

		return self::normalizePlannedHrefAttributes($html, $entriesByPosition, $currentZeroBasedPosition);
	}

	public static function normalizeCreatedAnchors(
		string $html,
		array $siteMap,
		array $orderToBlockId,
		?int $currentZeroBasedPosition = null
	): string
	{
		if ($html === '' || $orderToBlockId === [])
		{
			return $html;
		}

		if ($siteMap === [])
		{
			$siteMap = self::buildSiteMapFromOrderNumbers(array_keys($orderToBlockId));
		}

		$normalizedHtml = self::normalizePlannedAnchors($html, $siteMap, $currentZeroBasedPosition);
		$fallbackOrder = self::resolveFallbackOrder($siteMap, $orderToBlockId, $currentZeroBasedPosition);

		$pattern = '/(?<=\s|<)(href\s*=\s*)(["\']?)'
			. preg_quote(self::HREF_PREFIX, '/')
			. '(\d+)\2(?=\s|>|\/)/iu';

		return preg_replace_callback(
			$pattern,
			static function (array $matches) use ($orderToBlockId, $fallbackOrder): string
			{
				$order = (int)($matches[3] ?? 0);
				if ($order <= 0 || !isset($orderToBlockId[$order]))
				{
					$order = $fallbackOrder;
				}

				if ($order <= 0 || !isset($orderToBlockId[$order]))
				{
					return (string)($matches[0] ?? '');
				}

				$prefix = (string)($matches[1] ?? 'href=');
				$quote = (string)($matches[2] ?? '');

				return $prefix . $quote . self::FINAL_HREF_PREFIX . (int)$orderToBlockId[$order] . $quote;
			},
			$normalizedHtml,
		) ?? $normalizedHtml;
	}

	private static function normalizePlannedHrefAttributes(
		string $html,
		array $entriesByPosition,
		?int $currentZeroBasedPosition
	): string
	{
		$pattern = '/(?<=\s|<)(href\s*=\s*)(["\']?)'
			. preg_quote(self::HREF_PREFIX, '/')
			. '(\d+)\2(?=\s|>|\/)/iu';

		return preg_replace_callback(
			$pattern,
			static function (array $matches) use ($entriesByPosition, $currentZeroBasedPosition): string
			{
				$href = self::resolvePlannedHref(
					(int)($matches[3] ?? 0),
					'',
					$entriesByPosition,
					$currentZeroBasedPosition,
				);

				$prefix = (string)($matches[1] ?? 'href=');
				$quote = (string)($matches[2] ?? '');

				return $prefix . $quote . $href . $quote;
			},
			$html,
		) ?? $html;
	}

	private static function resolvePlannedHref(
		int $order,
		string $linkText,
		array $entriesByPosition,
		?int $currentZeroBasedPosition
	): string
	{
		if ($order > 0 && isset($entriesByPosition[$order]))
		{
			return (string)$entriesByPosition[$order]['anchor'];
		}

		$anchorByText = self::findAnchorByLinkText($linkText, $entriesByPosition);
		if ($anchorByText !== '')
		{
			return $anchorByText;
		}

		return self::resolveFallbackAnchor($entriesByPosition, $currentZeroBasedPosition);
	}

	private static function findAnchorByLinkText(string $linkText, array $entriesByPosition): string
	{
		$normalizedLinkText = self::normalizeComparableText($linkText);
		if ($normalizedLinkText === '')
		{
			return '';
		}

		foreach ($entriesByPosition as $entry)
		{
			$title = self::normalizeComparableText((string)($entry['navigation_title'] ?? ''));
			if ($title !== '' && $normalizedLinkText === $title)
			{
				return (string)$entry['anchor'];
			}
		}

		foreach ($entriesByPosition as $entry)
		{
			$title = self::normalizeComparableText((string)($entry['navigation_title'] ?? ''));
			if (
				mb_strlen($title) >= 3
				&& mb_strlen($normalizedLinkText) >= 3
				&& (
					str_contains($normalizedLinkText, $title)
					|| str_contains($title, $normalizedLinkText)
				)
			)
			{
				return (string)$entry['anchor'];
			}
		}

		return '';
	}

	private static function resolveFallbackAnchor(array $entriesByPosition, ?int $currentZeroBasedPosition): string
	{
		if ($currentZeroBasedPosition !== null)
		{
			$currentOrder = max(1, $currentZeroBasedPosition + 1);
			if (isset($entriesByPosition[$currentOrder]))
			{
				return (string)$entriesByPosition[$currentOrder]['anchor'];
			}
		}

		$firstEntry = reset($entriesByPosition);

		return is_array($firstEntry) ? (string)($firstEntry['anchor'] ?? self::buildHref(0)) : self::buildHref(0);
	}

	private static function resolveFallbackOrder(
		array $siteMap,
		array $orderToBlockId,
		?int $currentZeroBasedPosition
	): int
	{
		$entriesByPosition = self::indexSiteMapByPosition($siteMap);
		$fallbackAnchor = self::resolveFallbackAnchor($entriesByPosition, $currentZeroBasedPosition);
		$fallbackOrder = self::extractOrderFromHref($fallbackAnchor);
		if ($fallbackOrder > 0 && isset($orderToBlockId[$fallbackOrder]))
		{
			return $fallbackOrder;
		}

		foreach ($orderToBlockId as $order => $blockId)
		{
			if ((int)$order > 0 && (int)$blockId > 0)
			{
				return (int)$order;
			}
		}

		return 0;
	}

	private static function extractOrderFromHref(string $href): int
	{
		if (preg_match('/^' . preg_quote(self::HREF_PREFIX, '/') . '(\d+)$/u', $href, $matches))
		{
			return (int)$matches[1];
		}

		return 0;
	}

	private static function indexSiteMapByPosition(array $siteMap): array
	{
		$entries = [];
		foreach ($siteMap as $entry)
		{
			if (!is_array($entry))
			{
				continue;
			}

			$position = (int)($entry['position'] ?? 0);
			$anchor = trim((string)($entry['anchor'] ?? ''));
			if ($position <= 0 || $anchor === '')
			{
				continue;
			}

			$entries[$position] = [
				'position' => $position,
				'navigation_title' => trim((string)($entry['navigation_title'] ?? '')),
				'anchor' => $anchor,
			];
		}
		ksort($entries);

		return $entries;
	}

	private static function resolveNavigationTitle(array $spec, int $zeroBasedPosition): string
	{
		foreach (['navigation_title', 'goal', 'context'] as $key)
		{
			$title = trim((string)($spec[$key] ?? ''));
			if ($title !== '')
			{
				return self::normalizeTitle($title, $zeroBasedPosition);
			}
		}

		return self::normalizeTitle('', $zeroBasedPosition);
	}

	private static function normalizeTitle(string $title, int $zeroBasedPosition): string
	{
		$title = preg_replace('/\s+/u', ' ', trim($title)) ?: '';
		if ($title !== '')
		{
			return $title;
		}

		return 'Block ' . ($zeroBasedPosition + 1);
	}

	private static function normalizeComparableText(string $text): string
	{
		$charset = defined('SITE_CHARSET') ? SITE_CHARSET : 'UTF-8';
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, $charset);
		$text = preg_replace('/\s+/u', ' ', trim($text)) ?: '';

		return mb_strtolower($text);
	}
}
