<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\DataLoader;

use Bitrix\Booking\Entity\ExternalData\ItemType\CrmDealItemType;
use Bitrix\Booking\Internals\Container;

class DataLoaderFactory
{
	public function getByType(string $type): DataLoaderInterface|null
	{
		return match ($type)
		{
			CrmDealItemType::class => Container::getCrmDealDataLoader(),
			default => null,
		};
	}

	public function getTypeByModuleAndEntityType(string $moduleId, string $entityTypeId): string|null
	{
		foreach ($this->getTypes() as $type)
		{
			$typeInstance = new $type();
			if ($typeInstance->getModuleId() === $moduleId && $typeInstance->getEntityTypeId() === $entityTypeId)
			{
				return $type;
			}
		}

		return null;
	}

	private function getTypes(): array
	{
		return [
			CrmDealItemType::class,
		];
	}
}
