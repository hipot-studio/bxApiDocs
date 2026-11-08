<?php

declare(strict_types = 1);

namespace Bitrix\CrmMobile\Entity;

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\Intranet\ToolsManager;
use Bitrix\Crm\Item;
use Bitrix\Crm\Model\ItemCategoryTable;
use Bitrix\Crm\Restriction;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Result;

final class ClientCreation
{
	public function __construct(
		private readonly Container $container,
		private readonly UserPermissions $permissions,
		private readonly ToolsManager $toolsManager,
	)
	{
	}

	public static function isSourceTypeSupported(int $entityTypeId): bool
	{
		return in_array($entityTypeId, [\CCrmOwnerType::Contact, \CCrmOwnerType::Company], true);
	}

	public function resolveSource(int $entityTypeId, int $entityId): Result
	{
		$result = new Result();
		if (!self::isSourceTypeSupported($entityTypeId) || $entityId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		$source = $this->container->getFactory($entityTypeId)?->getItem(
			$entityId,
			[Item::FIELD_NAME_ID, Item::FIELD_NAME_CATEGORY_ID],
		);
		if ($source === null)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		return $this->checkSource($source)->setData(['source' => $source]);
	}

	public function checkTarget(Item $source, int $entityTypeId, ?int $categoryId): Result
	{
		$result = $this->checkSource($source);
		if (!$result->isSuccess())
		{
			return $result;
		}

		if (
			!in_array($entityTypeId, $this->getTargetEntityTypeIds($source->getEntityTypeId()), true)
			|| ($entityTypeId === \CCrmOwnerType::Deal && ($categoryId === null || $categoryId < 0))
		)
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		$metadataCategoryId = $categoryId ?? (self::isSourceTypeSupported($entityTypeId) ? 0 : null);
		$metadata = $this->getTargetMetadata([$entityTypeId], [$entityTypeId => $metadataCategoryId]);
		if (!isset($metadata[$entityTypeId]))
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		$permissions = $this->permissions->entityType();
		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			if (
				($categoryId > 0 && !DealCategory::exists($categoryId))
				|| !$permissions->canAddItemsInCategory($entityTypeId, $categoryId)
			)
			{
				return $result->addError(ErrorCode::getAccessDeniedError());
			}

			return $result;
		}

		$factory = $this->container->getFactory($entityTypeId);
		$defaultCategoryId = $this->getDefaultCategoryId($factory);
		$canAdd = $defaultCategoryId !== null
			? $permissions->canAddItemsInCategory($entityTypeId, $defaultCategoryId)
			: $permissions->canAddItems($entityTypeId);
		if (
			!$canAdd
			|| ($factory->isCategoriesSupported() && $defaultCategoryId === null)
			|| (
				self::isSourceTypeSupported($entityTypeId)
				&& !$permissions->canReadItemsInCategory($entityTypeId, 0)
			)
			|| ($categoryId !== null && !$permissions->canAddItemsInCategory($entityTypeId, $categoryId))
		)
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		return $result;
	}

	public static function getInitialData(Item $source, int $entityTypeId, array $clientInfo): array
	{
		if ($source->getEntityTypeId() === \CCrmOwnerType::Contact)
		{
			$data = [
				'CONTACT_IDS' => [$source->getId()],
				'CLIENT_INFO' => ['CONTACT_DATA' => [$clientInfo]],
			];
			if ($entityTypeId === \CCrmOwnerType::Company)
			{
				$data['CONTACT_ID'] = $data['CONTACT_IDS'];
			}

			return $data;
		}

		$isContact = $entityTypeId === \CCrmOwnerType::Contact;
		$fieldName = $isContact ? 'COMPANY_IDS' : 'COMPANY_ID';

		return [
			$fieldName => $isContact ? [$source->getId()] : $source->getId(),
			'CLIENT_INFO' => ['COMPANY_DATA' => [$clientInfo]],
		];
	}

	public function getMenuItems(Item $source): Result
	{
		$result = $this->checkSource($source);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$targetEntityTypeIds = $this->getTargetEntityTypeIds($source->getEntityTypeId());
		$metadata = $this->getTargetMetadata($targetEntityTypeIds);
		$items = [];
		foreach ($targetEntityTypeIds as $entityTypeId)
		{
			$factoryMetadata = $metadata[$entityTypeId] ?? null;
			if (!$factoryMetadata)
			{
				continue;
			}

			$factory = $this->container->getFactory($entityTypeId);
			$item = [
				'entityTypeIds' => [$entityTypeId],
				'title' => $factoryMetadata['title'],
			];
			if ($entityTypeId === \CCrmOwnerType::Deal)
			{
				$categoryIds = $this->getAllowedCategoryIds($factory);
				if (empty($categoryIds))
				{
					continue;
				}

				$item['allowedCategoryIds'] = $categoryIds;
			}
			else
			{
				$permissions = $this->permissions->entityType();
				$canAdd = $factory->isCategoriesSupported()
					? $permissions->canAddItemsInCategory($entityTypeId, $factory->getDefaultCategory()->getId())
					: $permissions->canAddItems($entityTypeId);
				if (!$canAdd)
				{
					continue;
				}
			}

			$items[] = $item;
		}

		return $result->setData(['items' => $items]);
	}

	private function getTargetMetadata(array $entityTypeIds, array $categories = []): array
	{
		$metadata = [];
		foreach (FactoryProvider::getFactoriesMetaData($categories, $entityTypeIds, $this->container, $this->permissions) as $item)
		{
			if (
				$item['supported']
				&& !$item['restricted']
				&& $this->toolsManager->checkEntityTypeAvailability($item['entityTypeId'])
			)
			{
				$metadata[$item['entityTypeId']] = $item;
			}
		}

		return $metadata;
	}

	private function getDefaultCategoryId(Factory $factory): ?int
	{
		if (!$factory->isCategoriesSupported())
		{
			return null;
		}
		if (self::isSourceTypeSupported($factory->getEntityTypeId()))
		{
			return 0;
		}

		$category = ItemCategoryTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE_ID', $factory->getEntityTypeId())
			->where('IS_DEFAULT', 'Y')
			->setLimit(1)
			->fetch()
		;

		return $category ? (int)$category['ID'] : null;
	}

	private function checkSource(Item $source): Result
	{
		$result = new Result();
		if (!self::isSourceTypeSupported($source->getEntityTypeId()) || $source->isNew())
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		if (
			!$this->permissions->item()->canRead($source->getEntityTypeId(), $source->getId())
			|| !Restriction\RestrictionManager::isConversionPermitted()
		)
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		return $result;
	}

	private function getTargetEntityTypeIds(int $sourceEntityTypeId): array
	{
		return [
			\CCrmOwnerType::Lead,
			\CCrmOwnerType::Deal,
			$sourceEntityTypeId === \CCrmOwnerType::Contact ? \CCrmOwnerType::Company : \CCrmOwnerType::Contact,
			\CCrmOwnerType::SmartInvoice,
			\CCrmOwnerType::Quote,
		];
	}

	private function getAllowedCategoryIds(Factory $factory): array
	{
		$permissions = $this->permissions;
		$categories = $permissions->category()->filterAvailableForReadingCategories($factory->getCategories());
		$ids = [];
		foreach ($categories as $category)
		{
			if ($permissions->entityType()->canAddItemsInCategory($factory->getEntityTypeId(), $category->getId()))
			{
				$ids[] = $category->getId();
			}
		}

		return $ids;
	}
}
