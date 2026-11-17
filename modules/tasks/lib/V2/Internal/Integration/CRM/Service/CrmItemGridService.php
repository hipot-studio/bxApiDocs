<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Service;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service\CrmItemVisibilityService;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Dto\CrmItemGridData;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemReference;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemVisibility;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemDataReader;
use CCrmEntitySelectorHelper;

/**
 * Single source of the CRM bindings a task-list page may render. The page hands over every raw
 * UF_CRM_TASK value at once and gets back a safe view per value: rights and existence decide the state,
 * and the real title is loaded only for elements that survived both checks.
 */
class CrmItemGridService
{
	/** @var array<int, array<string, array<string, CrmItemGridData>>> userId => value set => map */
	private array $gridDataMaps = [];

	public function __construct(
		private readonly CrmItemVisibilityService $crmItemVisibilityService,
		private readonly CrmItemDataReader $crmItemDataReader,
	)
	{
	}

	/**
	 * @param list<string> $values raw UF_CRM_TASK values of the whole page
	 * @return array<string, CrmItemGridData> keyed by the original value; unsupported and malformed
	 *                                        values are absent
	 */
	public function getGridDataMap(array $values, int $userId): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$references = $this->getUniqueReferences($values);
		if (empty($references))
		{
			return [];
		}

		$fingerprint = $this->getFingerprint($references);
		if (isset($this->gridDataMaps[$userId][$fingerprint]))
		{
			return $this->gridDataMaps[$userId][$fingerprint];
		}

		$map = $this->buildGridDataMap($references, $userId);
		$this->gridDataMaps[$userId][$fingerprint] = $map;

		return $map;
	}

	/**
	 * @param list<CrmItemReference> $references
	 * @return array<string, CrmItemGridData>
	 */
	private function buildGridDataMap(array $references, int $userId): array
	{
		$visibility = $this->crmItemVisibilityService->buildVisibilityMap($references, $userId);
		$details = $this->crmItemDataReader->readDetails($this->groupReadableIds($references, $visibility));

		$map = [];
		foreach ($references as $reference)
		{
			$key = $reference->key();
			$state = $visibility[$key] ?? CrmItemVisibility::NotFound;
			$detail = $details[$key] ?? null;

			// An element that vanished between the existence read and the title read is deleted, not hidden.
			if ($state === CrmItemVisibility::Readable && $detail === null)
			{
				$state = CrmItemVisibility::NotFound;
			}

			$map[$reference->value] = $this->buildGridData($reference, $state, $detail);
		}

		return $map;
	}

	/**
	 * @param list<CrmItemReference> $references
	 * @param array<string, CrmItemVisibility> $visibility
	 * @return array<int, list<int>>
	 */
	private function groupReadableIds(array $references, array $visibility): array
	{
		$entityIdsByType = [];
		foreach ($references as $reference)
		{
			if (($visibility[$reference->key()] ?? null) === CrmItemVisibility::Readable)
			{
				$entityIdsByType[$reference->typeId][$reference->entityId] = $reference->entityId;
			}
		}

		return array_map('array_values', $entityIdsByType);
	}

	/**
	 * @param array{title: string, typeName: string|null, url: string}|null $detail
	 */
	private function buildGridData(
		CrmItemReference $reference,
		CrmItemVisibility $visibility,
		?array $detail,
	): CrmItemGridData
	{
		$hiddenTitle = (string)CCrmEntitySelectorHelper::getHiddenTitle($reference->typeName);

		return match ($visibility)
		{
			CrmItemVisibility::Readable => CrmItemGridData::readable(
				$reference,
				$hiddenTitle,
				$detail['title'],
				$detail['url'],
				$detail['typeName'],
			),
			CrmItemVisibility::Hidden => CrmItemGridData::hidden($reference, $hiddenTitle),
			CrmItemVisibility::NotFound => CrmItemGridData::notFound($reference, $hiddenTitle),
		};
	}

	/**
	 * @param list<string> $values
	 * @return list<CrmItemReference> one reference per distinct raw value, in input order
	 */
	private function getUniqueReferences(array $values): array
	{
		$references = [];
		foreach ($values as $value)
		{
			$reference = CrmItemReference::tryFrom($value);
			if ($reference !== null && !isset($references[$reference->value]))
			{
				$references[$reference->value] = $reference;
			}
		}

		return array_values($references);
	}

	/**
	 * @param list<CrmItemReference> $references
	 */
	private function getFingerprint(array $references): string
	{
		$values = array_map(static fn (CrmItemReference $reference): string => $reference->value, $references);
		sort($values);

		return implode("\n", $values);
	}
}
