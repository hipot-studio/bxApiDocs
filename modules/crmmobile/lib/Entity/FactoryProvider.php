<?php

namespace Bitrix\CrmMobile\Entity;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Settings\Crm;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Main\Loader;

final class FactoryProvider
{
	/**
	 * @return Factory[]
	 */
	public static function getAvailableFactories(): array
	{
		$factories = Container::getInstance()->getTypesMap()->getFactories();

		$factories = self::filterSupportedFactories($factories);
		$factories = self::filterPermittedFactories($factories);

		return $factories;
	}

	/**
	 * @return Factory[]
	 */
	public static function getFactoriesMetaData(
		array $categories = [],
		?array $entityTypeIds = null,
		?Container $container = null,
		?UserPermissions $userPermissions = null,
	): array
	{
		$container ??= Container::getInstance();
		$userPermissions ??= $container->getUserPermissions();
		if (self::isExtranetUser($userPermissions->getUserId()))
		{
			return [];
		}

		$result = [];

		$factories = self::getFactories($entityTypeIds, $container);
		$includeDynamicTypes = $entityTypeIds === null
			|| !empty(array_filter($entityTypeIds, [\CCrmOwnerType::class, 'isPossibleDynamicTypeId']));
		$supportedEntityTypeIds = array_fill_keys(self::getSupportedTypes($includeDynamicTypes, $container), true);

		foreach ($factories as $factory)
		{
			$entityTypeId = $factory->getEntityTypeId();
			$categoryId = self::getCategoryIdForCheckPermission($factory, $categories);

			if (
				(is_null($categoryId) && $userPermissions->entityType()->canReadItems($entityTypeId))
				|| (!is_null($categoryId) && $userPermissions->entityType()->canReadItemsInCategory($entityTypeId, $categoryId))
			)
			{
				$result[] = [
					'entityTypeId' => $entityTypeId,
					'entityTypeName' => $factory->getEntityName(),
					'title' => $factory->getEntityDescription(),
					'supported' => isset($supportedEntityTypeIds[$entityTypeId]),
					'restricted' => RestrictionManager::isEntityRestricted($entityTypeId),
				];
			}
		}

		return $result;
	}

	public static function getSupportedEntityTypeIds(): array
	{
		return self::getSupportedTypes(true, Container::getInstance());
	}

	private static function getSupportedTypes(bool $includeDynamicTypes, Container $container): array
	{
		$entities = [];
		$dynamicEntities = [];

		if (LeadSettings::isEnabled())
		{
			$entities[] = \CCrmOwnerType::Lead;
		}

		if ($includeDynamicTypes && Crm::isMobileDynamicTypesEnabled())
		{
			$dynamicTypesMap = $container->getDynamicTypesMap()->load([
				'isLoadStages' => false,
				'isLoadCategories' => false,
			]);

			$dynamicEntities = array_map(
				static fn ($type) => $type->getEntityTypeId(),
				$dynamicTypesMap->getTypes()
			);
		}

		return array_merge(
			$entities,
			[
				\CCrmOwnerType::Deal,
				\CCrmOwnerType::Contact,
				\CCrmOwnerType::Company,
				\CCrmOwnerType::SmartInvoice,
				\CCrmOwnerType::Quote,
			],
			$dynamicEntities
		);
	}

	/**
	 * @return Factory[]
	 */
	private static function getFactories(?array $entityTypeIds, Container $container): array
	{
		if ($entityTypeIds === null)
		{
			return $container->getTypesMap()->getFactories();
		}

		$factories = [];
		foreach (array_unique($entityTypeIds) as $entityTypeId)
		{
			$factory = $container->getFactory($entityTypeId);
			if ($factory !== null)
			{
				$factories[] = $factory;
			}
		}

		return $factories;
	}

	private static function filterSupportedFactories(array $factories): array
	{
		$factoryMap = [];
		foreach ($factories as $factory)
		{
			$factoryMap[$factory->getEntityTypeId()] = $factory;
		}

		$result = [];

		foreach (self::getSupportedEntityTypeIds() as $entityTypeId)
		{
			$factory = $factoryMap[$entityTypeId] ?? null;
			if ($factory)
			{
				$result[] = $factory;
			}
		}

		return $result;
	}

	private static function filterPermittedFactories(array $factories): array
	{
		if (self::isExtranetUser())
		{
			return [];
		}

		$result = [];

		$userPermissions = Container::getInstance()->getUserPermissions();

		foreach ($factories as $factory)
		{
			$categoryId = self::getCategoryIdForCheckPermission($factory);
			if (
				(is_null($categoryId) && $userPermissions->entityType()->canReadItems($factory->getEntityTypeId()))
				|| (!is_null($categoryId) && $userPermissions->entityType()->canReadItemsInCategory($factory->getEntityTypeId(), $categoryId))
			)
			{
				$result[] = $factory;
			}
		}

		return $result;
	}

	private static function getCategoryIdForCheckPermission(Factory $factory, array $categories = []): ?int
	{
		$entityTypeId = $factory->getEntityTypeId();
		$categoryId = $categories[$entityTypeId] ?? null;
		if ($categoryId !== null)
		{
			return (int)$categoryId;
		}

		return (
		$entityTypeId === \CCrmOwnerType::Contact
			|| $entityTypeId === \CCrmOwnerType::Company
				? $factory->getDefaultCategory()->getId()
				: null
		);
	}

	private static function isExtranetUser(?int $userId = null): bool
	{
		return Loader::includeModule('intranet') && !\Bitrix\Intranet\Util::isIntranetUser($userId);
	}
}
