<?php

namespace Bitrix\BIConnector\Integration\Superset;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

final class CultureFormatter
{
	private const OPTION_MODULE = 'biconnector';
	private const LOCALIZATION_LANGUAGE_CODE_OPTION = 'superset_language_code';

	/**
	 * Returns currency symbol that used in superset entities phrases
	 *
	 * @return string
	 */
	public static function getPortalCurrencySymbol(): string
	{
		if (Loader::includeModule('crm'))
		{
			return html_entity_decode(\CCrmCurrency::GetCurrencyText(\CCrmCurrency::GetBaseCurrencyID()));
		}

		return '';
	}

	public static function setLanguageCode(string $languageCode): void
	{
		Option::set(self::OPTION_MODULE, self::LOCALIZATION_LANGUAGE_CODE_OPTION, $languageCode);
	}

	public static function getLanguageCode(): string
	{
		return self::resolveLanguageCode(Option::get(self::OPTION_MODULE, self::LOCALIZATION_LANGUAGE_CODE_OPTION));
	}

	/**
	 * Resolves a stored language code: an empty one falls back to the portal language and is saved back.
	 */
	public static function resolveLanguageCode(string $storedLanguageCode): string
	{
		if (!empty($storedLanguageCode))
		{
			return $storedLanguageCode;
		}

		$language = self::getPortalLanguageCode();
		self::setLanguageCode($language);

		return $language;
	}

	public static function getLanguage(): string
	{
		return self::getLanguageName(self::getLanguageCode());
	}

	public static function getLanguageName(string $languageCode): string
	{
		$languages = self::getLanguageList();

		foreach ($languages as $code => $language)
		{
			if ($code === $languageCode)
			{
				return $language['NAME'] ?? $languageCode;
			}
		}

		return $languageCode;
	}

	private static function getPortalLanguageCode(): string
	{
		$lang = \Bitrix\Main\SiteTable::getDefaultLanguageId();

		if (!empty($lang))
		{
			return $lang;
		}

		$contextLang = Application::getInstance()->getContext()?->getLanguage();

		if (!empty($contextLang))
		{
			return $contextLang;
		}

		return 'en';
	}

	public static function getLanguageList(): array
	{
		return self::normalizeLanguageList(self::readLanguageList());
	}

	private static function readLanguageList(): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		if (Loader::includeModule('bitrix24'))
		{
			return \Bitrix\Intranet\Util::getTemplateLanguages();
		}

		$result = [];
		$repository = new \Bitrix\Intranet\Internal\Repository\LanguageRepository();
		foreach ($repository->getPortalLanguages() as $language)
		{
			$result[$language['ID']] = ['NAME' => $language['NAME']];
		}

		return $result;
	}

	/**
	 * Gives every entry a non-empty NAME: a language saved without a name is shown by its code.
	 */
	private static function normalizeLanguageList(array $languages): array
	{
		foreach ($languages as $code => $language)
		{
			if (empty($language['NAME']))
			{
				$languages[$code]['NAME'] = (string)$code;
			}
		}

		return $languages;
	}
}
