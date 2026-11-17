<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Sorter;

use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\Mapper\CrmIdMapper;

final class CrmItemIdSorter
{
	private const STATIC_ORDER = [
		2 => 0,
		3 => 1,
		4 => 2,
		1 => 3,
		31 => 4,
	];

	public function __construct(
		private readonly CrmIdMapper $crmIdMapper,
	)
	{
	}

	/**
	 * @param list<string> $ids
	 * @return list<string>
	 */
	public function sortIds(array $ids): array
	{
		$sortable = [];
		$tail = [];

		foreach ($ids as $position => $id)
		{
			if (!is_string($id))
			{
				$tail[] = $id;

				continue;
			}

			$parsedId = $this->crmIdMapper->mapFromId($id);
			if ($parsedId === null)
			{
				$tail[] = $id;

				continue;
			}

			[$typeId, $entityId] = $parsedId;
			$sortable[] = [
				'sort' => self::STATIC_ORDER[$typeId] ?? $typeId,
				'entityId' => $entityId,
				'position' => $position,
				'id' => $id,
			];
		}

		usort(
			$sortable,
			static fn (array $left, array $right): int => [
				$left['sort'],
				$left['entityId'],
				$left['position'],
			] <=> [
				$right['sort'],
				$right['entityId'],
				$right['position'],
			]
		);

		return array_merge(array_column($sortable, 'id'), $tail);
	}
}
