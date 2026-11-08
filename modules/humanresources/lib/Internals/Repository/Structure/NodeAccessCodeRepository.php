<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Repository\Structure;

use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Model\NodeBackwardAccessCodeTable;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

final class NodeAccessCodeRepository
{
	/**
	 * How many access codes one query asks about. A binding of the old structure is normally a handful of
	 * codes, but nothing bounds it from above - a value written by an import or by an older dialog can hold
	 * as many as it likes, and a single `IN` of that size is a query no index makes cheap.
	 */
	private const ACCESS_CODES_PER_QUERY = 500;

	public function createByNode(Node $node): ?string
	{
		$existed =
			NodeBackwardAccessCodeTable::query()
				->addSelect('ACCESS_CODE')
				->where('NODE_ID', $node->id)
				->setLimit(1)
				->fetch()
		;

		if ($existed)
		{
			return $existed['ACCESS_CODE'];
		}
		$node->accessCode = AccessCodeType::HrStructureNodeType->value . $node->id;

		return $node->accessCode;
	}

	/**
	 * The nodes a list of access codes names, in one list. A node is answered with once however many of the
	 * codes asked named it: the grouped reading below answers every code that asked, and `D5` with `DR5`,
	 * or a code repeated in the list, are such codes - while the `IN` this used to be deduplicated them by
	 * itself.
	 *
	 * @param array<string> $accessCodes
	 *
	 * @return list<int>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getNodeIdsByAccessCodes(array $accessCodes): array
	{
		$result = [];
		foreach ($this->findNodeIdsGroupedByAccessCode($accessCodes) as $nodeIds)
		{
			foreach ($nodeIds as $nodeId)
			{
				$result[] = $nodeId;
			}
		}

		return array_values(array_unique($result));
	}

	/**
	 * The same reading, keeping which code each node came from: a caller that has to tell an access code
	 * resolving into nothing from one resolving into a node cannot do it over a flat list of ids.
	 *
	 * Keys are the codes as they were asked for, and a code the structure knows nothing about is absent
	 * from the answer rather than present with an empty list.
	 *
	 * @param array<string> $accessCodes
	 *
	 * @return array<string, list<int>>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findNodeIdsGroupedByAccessCode(array $accessCodes): array
	{
		if (empty($accessCodes))
		{
			return [];
		}

		// The recursive kind of a code names the same section as the plain one, so both are asked about
		// under the plain code and the answer is handed back to every code that asked.
		$askedUnder = [];
		foreach (array_unique($accessCodes) as $accessCode)
		{
			$normalized = str_replace(
				AccessCodeType::IntranetDepartmentRecursiveType->value,
				AccessCodeType::IntranetDepartmentType->value,
				$accessCode,
			);

			$askedUnder[$normalized][] = $accessCode;
		}

		$grouped = [];
		foreach (array_chunk(array_keys($askedUnder), self::ACCESS_CODES_PER_QUERY) as $chunk)
		{
			$rows =
				NodeBackwardAccessCodeTable::query()
					->addSelect('NODE_ID')
					->addSelect('ACCESS_CODE')
					->whereIn('ACCESS_CODE', $chunk)
					->fetchAll()
			;

			foreach ($rows as $row)
			{
				foreach ($askedUnder[$row['ACCESS_CODE']] ?? [] as $accessCode)
				{
					$grouped[$accessCode][] = (int)$row['NODE_ID'];
				}
			}
		}

		return $grouped;
	}
}