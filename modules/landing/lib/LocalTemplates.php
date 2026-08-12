<?php
namespace Bitrix\Landing;

class LocalTemplates
{
	/**
	 * Curated list of local demo templates shown by the landing.demo UI picker.
	 * Kept in sync with install/components/bitrix/landing.demo/class.php.
	 */
	public static function getActiveList(): array
	{
		return [
			'empty',
			'empty-multipage',
			'wiki-dark',
			'wiki-light',
			'store_v3',
			'store-chats-dark',
			'clothes',
			'store-mini-catalog',
			'store-mini-one-element',
			'search-result',
			'search-result2',
			'search-result3-dark',
			'news-detail',
			'requisites',
			'ent-en',
		];
	}

	/**
	 * Full list of local demo template codes, frozen as of the fix date. This is the
	 * source of truth for TPL_CODE validation before include (LFI, #250845) — it is a
	 * closed set, not read from disk at runtime.
	 */
	public static function getFullList(): array
	{
		return [
			'accounting',
			'agency',
			'app',
			'architecture',
			'business',
			'charity',
			'clothes',
			'construction',
			'consulting',
			'corporate',
			'courses',
			'empty',
			'empty-multipage',
			'events',
			'gym',
			'lawyer',
			'music',
			'news-detail',
			'photography',
			'real-estate',
			'requisites',
			'restaurant',
			'search-result',
			'search-result2',
			'search-result3-dark',
			'shipping',
			'spa',
			'store-chats-dark',
			'store-mini-catalog',
			'store-mini-one-element',
			'store_v3',
			'travel',
			'wedding',
			'wiki-dark',
			'wiki-light',
			'holidays/holiday.23february.1',
			'holidays/holiday.23february.2',
			'holidays/holiday.23february.3',
			'holidays/holiday.8march1',
			'holidays/holiday.8march2',
			'holidays/holiday.8march3',
			'holidays/holiday.april-fool',
			'holidays/holiday.blackfriday',
			'holidays/holiday.easter1',
			'holidays/holiday.easter2',
			'holidays/holiday.halloween',
			'holidays/holiday.new-year',
			'holidays/holiday.thanksgiving',
			'holidays/holiday.valentine1',
			'holidays/holiday.valentine2',
			'holidays/holiday.valentine3',
			'holidays/holiday.xmas',
		];
	}

	/**
	 * Whether $code exactly matches one of the known local demo template codes.
	 */
	public static function isLocalTemplate(string $code): bool
	{
		return in_array($code, self::getFullList(), true);
	}
}
