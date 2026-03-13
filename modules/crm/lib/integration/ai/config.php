<?php

namespace Bitrix\Crm\Integration\AI;

use Bitrix\AI\Facade;
use Bitrix\Crm\Service\Container;
use CUserOptions;

final class Config
{
	public const MODULE_ID = 'crm';
	public const CODE_PREFIX = 'ai_config';
	public const LANGUAGE_CODE = 'languageId';

	// Map for language codes that differ between AI and Bitrix24
	private const LANGUAGE_CODE_MAP = [
		'kz' => 'kk',
	];

	public static function getAll(int $userId, int $entityTypeId, ?int $categoryId): array
	{
		$config = CUserOptions::GetOption(
			self::MODULE_ID,
			self::getOptionName($entityTypeId, $categoryId),
			[],
			$userId
		);

		return is_array($config) ? $config : [];
	}

	public static function getLanguageId(int $userId, int $entityTypeId, ?int $categoryId): string
	{
		$config = self::getAll($userId, $entityTypeId, $categoryId);
		$languageId = $config[self::LANGUAGE_CODE] ?? '';
		if (
			empty($languageId)
			|| !self::isValidLanguageId($languageId)
		)
		{
			$languageId = self::getDefaultLanguageId();

			// save default language for correct work copilot features, that depends on this config
			if (self::isValidLanguageId($languageId))
			{
				CUserOptions::SetOption(
					self::MODULE_ID,
					self::getOptionName($entityTypeId, $categoryId),
					[self::LANGUAGE_CODE => $languageId],
					false,
					$userId
				);
			}
		}

		return $languageId;
	}

	public static function getDefaultLanguageId(): string
	{
		if (AIManager::isAvailable())
		{
			$languageId = Facade\User::getUserLanguage();

			return self::LANGUAGE_CODE_MAP[$languageId] ?? $languageId;
		}

		return '';
	}

	private static function getOptionName(int $entityTypeId, ?int $categoryId): string
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($categoryId === null && $factory?->isCategoriesSupported())
		{
			$categoryId = $factory?->createDefaultCategoryIfNotExist()->getId();
		}

		$typeKey = (string)($entityTypeId);
		if ($categoryId !== null)
		{
			$typeKey .= "_{$categoryId}";
		}

		return self::CODE_PREFIX . "_{$typeKey}";
	}

	private static function isValidLanguageId(string $languageId): bool
	{
		return array_key_exists($languageId, AIManager::getAvailableLanguageList());
	}
}
