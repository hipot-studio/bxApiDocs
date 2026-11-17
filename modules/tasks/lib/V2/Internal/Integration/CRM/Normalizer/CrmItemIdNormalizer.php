<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Normalizer;

use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\Mapper\CrmIdMapper;

final class CrmItemIdNormalizer
{
	public function __construct(
		private readonly CrmIdMapper $crmIdMapper,
	)
	{
	}

	/**
	 * @param list<string> $ids
	 * @return list<string>
	 */
	public function getUniqueIds(array $ids): array
	{
		$uniqueIds = [];
		$seen = [];

		foreach ($ids as $id)
		{
			if (!is_string($id))
			{
				continue;
			}

			$parsedId = $this->crmIdMapper->mapFromId($id);
			if ($parsedId === null)
			{
				$uniqueIds[] = $id;

				continue;
			}

			[$typeId, $entityId] = $parsedId;
			$key = $typeId . ':' . $entityId;
			if (isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = true;
			$canonicalId = $this->crmIdMapper->mapToId($typeId, $entityId);
			if (is_string($canonicalId))
			{
				$uniqueIds[] = $canonicalId;
			}
		}

		return $uniqueIds;
	}
}
