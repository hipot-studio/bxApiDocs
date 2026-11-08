<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Category\Entity\DealCategoryTable;
use Bitrix\Crm\Model\ItemCategoryTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Error;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Exception;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * @internal
 */
final class ContextResolver
{
	public function resolve(EntityType $entityType, ?int $categoryId): Context
	{
		$this->assertSupported($entityType);

		$factory = Container::getInstance()->getFactory($entityType->getId());
		if ($factory === null)
		{
			throw new Exception(Error::ENTITY_TYPE_NOT_FOUND);
		}

		if ($this->doesNotUseCategory($entityType))
		{
			if ($categoryId !== null)
			{
				throw new Exception(Error::CATEGORY_ID_NOT_ALLOWED);
			}

			return new Context(null, 0, $factory->isStagesEnabled());
		}

		if ($categoryId === null)
		{
			throw new Exception(Error::CATEGORY_ID_REQUIRED);
		}

		$isZeroAllowed = $this->isZeroCategoryAllowed($entityType);
		if ($categoryId < 0 || ($categoryId === 0 && !$isZeroAllowed))
		{
			throw new Exception(Error::CATEGORY_ID_INVALID);
		}

		if ($categoryId > 0)
		{
			$this->assertCategoryBelongsToEntityType($entityType, $categoryId);
		}

		return new Context($categoryId, $categoryId, $factory->isStagesEnabled());
	}

	private function assertSupported(EntityType $entityType): void
	{
		if ($entityType->isSmartProcess())
		{
			return;
		}

		if (!in_array(
			$entityType->getId(),
			[
				\CCrmOwnerType::Lead,
				\CCrmOwnerType::Deal,
				\CCrmOwnerType::Contact,
				\CCrmOwnerType::Company,
				\CCrmOwnerType::Quote,
				\CCrmOwnerType::SmartInvoice,
			],
			true,
		))
		{
			throw new Exception(Error::ENTITY_TYPE_NOT_SUPPORTED);
		}
	}

	private function doesNotUseCategory(EntityType $entityType): bool
	{
		return in_array($entityType->getId(), [\CCrmOwnerType::Lead, \CCrmOwnerType::Quote], true);
	}

	private function isZeroCategoryAllowed(EntityType $entityType): bool
	{
		return in_array(
			$entityType->getId(),
			[\CCrmOwnerType::Deal, \CCrmOwnerType::Contact, \CCrmOwnerType::Company],
			true,
		);
	}

	private function assertCategoryBelongsToEntityType(EntityType $entityType, int $categoryId): void
	{
		if ($entityType->getId() === \CCrmOwnerType::Deal)
		{
			if (DealCategoryTable::getByPrimary($categoryId)->fetch() === false)
			{
				throw new Exception(Error::CATEGORY_NOT_FOUND);
			}

			return;
		}

		$row = ItemCategoryTable::query()
			->setSelect(['ENTITY_TYPE_ID'])
			->where('ID', $categoryId)
			->fetch()
		;
		if ($row === false)
		{
			throw new Exception(Error::CATEGORY_NOT_FOUND);
		}

		if ((int)$row['ENTITY_TYPE_ID'] !== $entityType->getId())
		{
			throw new Exception(Error::CATEGORY_ENTITY_TYPE_MISMATCH);
		}
	}
}
