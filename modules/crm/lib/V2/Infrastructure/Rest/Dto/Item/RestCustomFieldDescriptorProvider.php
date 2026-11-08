<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item;

use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldDescriptor;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Infrastructure\Rest\Service\CategoryUserFieldFilter;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\OwnerType;

/**
 * @internal
 */
final class RestCustomFieldDescriptorProvider
{
	private const DEFAULT_CATEGORY_ID = 0;

	public function __construct(
		private readonly CustomFieldRegistry $customFieldRegistry,
		private readonly CategoryUserFieldFilter $categoryUserFieldFilter = new CategoryUserFieldFilter(),
	)
	{
	}

	/**
	 * @return array<string, CustomFieldDescriptor>
	 */
	public function getCustomFieldDescriptorsMapByName(EntityType $entityType): array
	{
		$descriptors = $this->customFieldRegistry->getEntityDescriptorsMap($entityType);
		if (!$this->isCategoryDependent($entityType))
		{
			return $descriptors;
		}

		$allowedFieldNames = $this->categoryUserFieldFilter->filter(
			EntityType::fromId($entityType->getId()),
			self::DEFAULT_CATEGORY_ID,
			array_fill_keys(array_keys($descriptors), true),
		);

		return array_intersect_key($descriptors, $allowedFieldNames);
	}

	private function isCategoryDependent(EntityType $entityType): bool
	{
		return in_array($entityType->getId(), [OwnerType::CONTACT, OwnerType::COMPANY], true);
	}
}
