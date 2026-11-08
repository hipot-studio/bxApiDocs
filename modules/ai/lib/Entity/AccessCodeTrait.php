<?php declare(strict_types=1);

namespace Bitrix\AI\Entity;

use Bitrix\Main\Access\AccessCode;

trait AccessCodeTrait
{
	/**
	 * Collects deduplicated entity ids of the codes belonging to the given entity type.
	 *
	 * @param string[] $codes
	 * @param string $entityType One of the AccessCode::TYPE_* constants
	 * @return list<int>
	 */
	protected function collectEntityIds(array $codes, string $entityType): array
	{
		return $this->collectEntityIdsByTypes($codes, [$entityType])[$entityType];
	}

	/**
	 * Collects deduplicated entity ids of the codes, bucketed by the given entity types.
	 *
	 * @param string[] $codes
	 * @param string[] $entityTypes The AccessCode::TYPE_* constants to collect
	 * @return array<string, list<int>> Every requested type is present, possibly with an empty list
	 */
	protected function collectEntityIdsByTypes(array $codes, array $entityTypes): array
	{
		$buckets = array_fill_keys($entityTypes, []);

		foreach ($codes as $code)
		{
			$accessCode = new AccessCode($code);
			$entityType = $accessCode->getEntityType();
			if (!array_key_exists($entityType, $buckets))
			{
				continue;
			}

			$id = $accessCode->getEntityId();
			if ($id <= 0)
			{
				continue;
			}

			$buckets[$entityType][$id] = $id;
		}

		return array_map('array_values', $buckets);
	}
}
