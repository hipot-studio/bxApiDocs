<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Service;

use Bitrix\Main\Loader;
use CCrmOwnerType;

class CrmOwnerTypeService
{
	public function resolveId(string $typeName): int
	{
		if (!Loader::includeModule('crm'))
		{
			return 0;
		}

		return (int)CCrmOwnerType::ResolveID($typeName);
	}

	public function getCaption(int $typeId, int $id): string
	{
		if (!Loader::includeModule('crm'))
		{
			return '';
		}

		return (string)CCrmOwnerType::GetCaption($typeId, $id);
	}

	public function getEntityPath(int $typeId, int $id): string
	{
		if (!Loader::includeModule('crm'))
		{
			return '';
		}

		return (string)CCrmOwnerType::GetEntityShowPath($typeId, $id);
	}
}

