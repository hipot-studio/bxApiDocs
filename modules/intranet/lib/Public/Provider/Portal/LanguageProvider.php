<?php

namespace Bitrix\Intranet\Public\Provider\Portal;

use Bitrix\Intranet\Internal\Repository\LanguageRepository;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;

class LanguageProvider
{
	private const BETA_LANGUAGE_LIST = ['kz', 'ar'];

	public function getPublicArray(): array
	{
		$languagesRes = (new LanguageRepository())->getPortalLanguages();
		$languages = [];
		$hiddenLanguagesIds = $this->getHiddenLanguagesIds();

		foreach ($languagesRes as $language)
		{
			if (in_array($language['ID'], $hiddenLanguagesIds))
			{
				continue;
			}

			$value = [
				'NAME' => $language['NAME'],
				'IS_BETA' => in_array($language['ID'], self::BETA_LANGUAGE_LIST),
			];

			if ($language['ID'] === LANGUAGE_ID)
			{
				$languages = [$language['ID'] => $value] + $languages;
			}
			else
			{
				$languages[$language['ID']] = $value;
			}
		}

		return $languages;
	}

	public function isLanguageIdChangeAvailable(): bool
	{
		return
			ModuleManager::isModuleInstalled('bitrix24')
			|| (defined('INTRANET_LANGUAGE_ID_CHANGE_AVAILABLE') && INTRANET_LANGUAGE_ID_CHANGE_AVAILABLE);
	}

	private function getHiddenLanguagesIds(): array
	{
		$hiddenLangs = Option::get('intranet', 'hidden_langs_from_public');

		if (!empty($hiddenLangs))
		{
			return explode(',', $hiddenLangs);
		}

		return [];
	}
}
