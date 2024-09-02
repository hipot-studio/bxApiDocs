<?php

namespace Bitrix\AI\Entity;

trait TranslateTrait
{
	/**
	 * Return translate by translates and langCode
	 *
	 * @param array $translates
	 * @param string $langCode
	 * @return string
	 */
	static private function translate(array $translates, string $langCode): string
	{
		if (array_key_exists($langCode, $translates))
		{
			return $translates[$langCode];
		}

		return $translates['en'] ?? '';
	}
}