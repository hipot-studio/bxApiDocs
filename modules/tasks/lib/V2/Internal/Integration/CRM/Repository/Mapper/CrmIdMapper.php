<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\Mapper;

use Bitrix\Main\Loader;
use CCrmOwnerType;
use CCrmOwnerTypeAbbr;

class CrmIdMapper
{
	public function mapFromId(string $id): ?array
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		[$entityType, $entityId] = array_pad(explode('_', $id, 2), 2, '');

		$entityId = (int)$entityId;
		$typeId = CCrmOwnerType::ResolveID(CCrmOwnerTypeAbbr::ResolveName($entityType));
		if ($typeId === CCrmOwnerType::Undefined)
		{
			return null;
		}

		return [$typeId, $entityId];
	}

	public function mapToId(int $typeId, int $entityId): ?string
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		$entityType = CCrmOwnerTypeAbbr::ResolveByTypeID($typeId);
		if ($entityType === CCrmOwnerTypeAbbr::Undefined)
		{
			return null;
		}

		return $entityType . '_' . $entityId;
	}
}