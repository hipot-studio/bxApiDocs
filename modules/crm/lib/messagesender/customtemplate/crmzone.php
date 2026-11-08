<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\CustomTemplate;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\MessageService\Public\Provider\CustomTemplate\Zone\AbstractZoneProvider;

/**
 * CRM-side view of the custom-template zone: the zone id and the cleanup entry points,
 * reachable without loading a single messageservice custom-template class.
 *
 * {@see CrmZoneProvider} implements the messageservice contract, so merely touching its
 * constants autoloads a class extending {@see AbstractZoneProvider}. Code that runs
 * regardless of the feature - item detail pages, category and type deletion - must go
 * through this class instead.
 */
final class CrmZone
{
	public const ID = 'crm';

	private const CLEANUP_SERVICE = 'messageservice.customTemplate.zoneCleanupService';

	private static ?bool $isSupported = null;

	/**
	 * Whether the installed messageservice knows about custom templates at all.
	 * AbstractZoneProvider is the root of the feature's public surface there, so its
	 * presence stands for the whole set: zone registry, cleanup service and the
	 * custom-template layout flags.
	 */
	public static function isSupportedByMessageService(): bool
	{
		self::$isSupported ??= Loader::includeModule('messageservice')
			&& class_exists(AbstractZoneProvider::class);

		return self::$isSupported;
	}

	public static function cleanupTemplatesByTarget(int $entityTypeId, int $categoryId): void
	{
		if (!self::hasCleanupService())
		{
			return;
		}

		ServiceLocator::getInstance()
			->get(self::CLEANUP_SERVICE)
			->cleanupByTarget(
				self::ID,
				(new CrmTemplateTargetId($entityTypeId, $categoryId))->toString(),
			)
		;
	}

	public static function cleanupTemplatesByEntityType(int $entityTypeId): void
	{
		if (!self::hasCleanupService())
		{
			return;
		}

		ServiceLocator::getInstance()
			->get(self::CLEANUP_SERVICE)
			->cleanupByTargetPrefix(
				self::ID,
				CrmTemplateTargetId::entityTypeIdPrefix($entityTypeId),
			)
		;
	}

	private static function hasCleanupService(): bool
	{
		return Loader::includeModule('messageservice')
			&& ServiceLocator::getInstance()->has(self::CLEANUP_SERVICE);
	}
}
