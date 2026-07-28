<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Integration;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Notifications\FeatureStatus;
use Bitrix\Notifications\Settings;
use Bitrix\Notifications\Template;

final class Notifications
{
	/** @see Settings::SCENARIO_CRM_PAYMENT */
	public const SCENARIO_CRM_PAYMENT = 'CRM_PAYMENT';

	public static function canSendMessage(string $scenario): bool
	{
		return (
			self::isModulesInstalled()
			&& self::isAvailableInRegion($scenario)
			&& !self::isLimited($scenario)
			&& self::isConnected($scenario)
		);
	}

	public static function isAvailableInRegion(string $scenario): bool
	{
		if (!Loader::includeModule('notifications'))
		{
			return false;
		}

		return Settings::getScenarioAvailability($scenario) !== FeatureStatus::UNAVAILABLE;
	}

	public static function isLimited(string $scenario): bool
	{
		if (!Loader::includeModule('notifications'))
		{
			return true;
		}

		return Settings::getScenarioAvailability($scenario) === FeatureStatus::LIMITED;
	}

	private static function isConnected(string $scenario): bool
	{
		if (!Loader::includeModule('notifications'))
		{
			return false;
		}

		return Settings::isScenarioEnabled($scenario);
	}

	public static function isModulesInstalled(): bool
	{
		return Loader::includeModule('notifications')
			&& Loader::includeModule('imconnector');
	}

	public static function getTemplateTranslation(string $templateCode): ?array
	{
		$translations = self::getMultipleTemplateTranslations([$templateCode]);

		return $translations[$templateCode] ?? null;
	}

	public static function getMultipleTemplateTranslations(array $templateCodes): array
	{
		if (!Loader::includeModule('notifications'))
		{
			return [];
		}

		$lang = self::getDefaultLanguageId();
		$result = Template::getMultipleTranslations($templateCodes, $lang);
		if (!$result->isSuccess())
		{
			return [];
		}

		$translations = [];
		foreach ($result->getData() as $code => $translation)
		{
			if (is_array($translation[0] ?? null))
			{
				$translations[$code] = $translation[0];
			}
		}

		return $translations;
	}

	private static function getDefaultLanguageId(): string
	{
		return Application::getInstance()->getLicense()->getRegion() ?? LANGUAGE_ID;
	}
}
