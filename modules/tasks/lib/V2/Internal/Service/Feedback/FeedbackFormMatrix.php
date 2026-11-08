<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Feedback;

/**
 * Static registry of CRM feedback forms resolved by portal region and interface language.
 * Values (id/sec/portal) come from codes.xlsx and are the single source of truth for the feature.
 */
final class FeedbackFormMatrix
{
	private const GLOBAL_PORTAL = 'https://global.bitrix24.com';

	/**
	 * CIS block: selected by portal region. KZ additionally distinguishes by language.
	 *
	 * @return FeedbackFormRow[]
	 */
	private function cisRows(): array
	{
		return [
			new FeedbackFormRow(['ru'], 'ru', '3282', '8x81lp', 'https://bitrix24.team'),
			new FeedbackFormRow(['by'], 'ru', '3', 'kde6g5', 'https://belarus.bitrix24.by'),
			new FeedbackFormRow(['kz'], 'kz', '4', 'id3qfm', 'https://teamkz.bitrix24.kz'),
			new FeedbackFormRow(['kz'], 'ru', '2', 'po3skw', 'https://teamkz.bitrix24.kz'),
			new FeedbackFormRow(['kz'], 'en', '6', 'gozt5o', 'https://teamkz.bitrix24.kz'),
			new FeedbackFormRow(['uz'], 'ru', '7', 'xjkuqu', 'https://team.bitrix24.uz'),
		];
	}

	/**
	 * Western block: selected by interface language. All forms live on the global portal.
	 *
	 * @return FeedbackFormRow[]
	 */
	private function westRows(): array
	{
		return [
			new FeedbackFormRow([], 'en', '34', '940xiq', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'br', '58', 'db0u10', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'la', '82', 'f5cg69', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'pl', '84', 'xaq93h', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'tr', '86', '6xbruy', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'vn', '88', '8g591b', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'it', '90', 'mc95rg', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'de', '92', 'uokzr7', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'fr', '94', 'gyzkzb', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'ms', '96', 'zaetk0', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'id', '108', '3gs5vj', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'sc', '98', '532jfn', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'th', '100', '1q4cis', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'ja', '102', 'c84t56', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'tc', '104', '6s7a1m', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'ar', '110', 'zfkgno', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'ru', '106', 'j1h7w4', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'ua', '34', '940xiq', self::GLOBAL_PORTAL),
			new FeedbackFormRow([], 'kz', '34', '940xiq', self::GLOBAL_PORTAL),
		];
	}

	public function matchByRegion(?string $region, string $lang): ?FeedbackFormRow
	{
		if ($region === null)
		{
			return null;
		}

		$candidates = array_filter(
			$this->cisRows(),
			static fn (FeedbackFormRow $row): bool => in_array($region, $row->zones, true),
		);

		if ($candidates === [])
		{
			return null;
		}

		foreach ($candidates as $row)
		{
			if ($row->lang === $lang)
			{
				return $row;
			}
		}

		return array_values($candidates)[0];
	}

	public function matchByLang(string $lang): ?FeedbackFormRow
	{
		foreach ($this->westRows() as $row)
		{
			if ($row->lang === $lang)
			{
				return $row;
			}
		}

		return null;
	}

	public function fallbackWestEn(): FeedbackFormRow
	{
		return new FeedbackFormRow([], 'en', '34', '940xiq', self::GLOBAL_PORTAL);
	}
}
