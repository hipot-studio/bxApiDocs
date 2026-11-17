<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\CRM\Repository;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service\CrmItemVisibilityService;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Dto\CrmItemPreviewResult;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemCollection;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemReference;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\Mapper\CrmIdMapper;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\Mapper\CrmItemMapper;

class CrmItemRepository implements CrmItemRepositoryInterface
{
	public function __construct(
		private readonly CrmItemMapper $crmItemMapper,
		private readonly CrmIdMapper $crmIdMapper,
		private readonly CrmItemVisibilityService $crmItemVisibilityService,
		private readonly CrmItemDataReader $crmItemDataReader,
	)
	{

	}

	public function getIdsByTaskId(int $taskId): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$row =
			TaskTable::query()
				->setSelect(['ID', UserField::TASK_CRM])
				->where('ID', $taskId)
				->fetch()
		;

		if (!is_array($row[UserField::TASK_CRM] ?? null))
		{
			return [];
		}

		return $row[UserField::TASK_CRM];
	}

	public function getIdsByTemplateId(int $templateId): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$row =
			TemplateTable::query()
				->setSelect(['ID', UserField::TASK_CRM])
				->where('ID', $templateId)
				->fetch()
		;

		if (!is_array($row[UserField::TASK_CRM] ?? null))
		{
			return [];
		}

		return $row[UserField::TASK_CRM];
	}

	public function getIdsByTaskIds(array $taskIds): array
	{
		if (empty($taskIds))
		{
			return [];
		}

		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$rows =
			TaskTable::query()
				->setSelect(['ID', UserField::TASK_CRM])
				->whereIn('ID', $taskIds)
				->fetchAll()
		;

		$items = [];
		foreach ($rows as $row)
		{
			$taskId = (int)($row['ID'] ?? 0);
			$crm = is_array($row[UserField::TASK_CRM] ?? null) ? $row[UserField::TASK_CRM] : [];

			$items[$taskId] = $crm;
		}

		return $items;
	}

	public function getReadableByIds(array $ids, int $userId): CrmItemCollection
	{
		$readableIds = $this->crmItemVisibilityService->filterReadable($ids, $userId);
		if (empty($readableIds))
		{
			return new CrmItemCollection();
		}

		return $this->getByReadableIdsBatch($readableIds);
	}

	public function getPreviewByIds(array $ids, int $userId, int $limit): CrmItemPreviewResult
	{
		$readableIds = $this->crmItemVisibilityService->filterReadable($ids, $userId);
		if (empty($readableIds))
		{
			return new CrmItemPreviewResult(new CrmItemCollection(), 0);
		}

		// CRM permissions are authoritative; missing items are skipped without backfilling the preview slice.
		$total = count($readableIds);
		$items = $this->getByReadableIdsBatch(array_slice($readableIds, 0, max(0, $limit)));

		return new CrmItemPreviewResult($items, $total);
	}

	public function invalidate(int $taskId): void
	{

	}

	private function getByReadableIdsBatch(array $ids): CrmItemCollection
	{
		$idsByType = [];
		foreach ($ids as $id)
		{
			$parsedId = $this->crmIdMapper->mapFromId($id);
			if ($parsedId === null)
			{
				continue;
			}

			[$typeId, $entityId] = $parsedId;
			$idsByType[$typeId][$entityId] = $entityId;
		}

		$details = $this->crmItemDataReader->readDetails(array_map('array_values', $idsByType));

		$items = [];
		foreach ($ids as $id)
		{
			$parsedId = $this->crmIdMapper->mapFromId($id);
			if ($parsedId === null)
			{
				continue;
			}

			[$typeId, $entityId] = $parsedId;
			$detail = $details[CrmItemReference::makeKey($typeId, $entityId)] ?? null;
			if ($detail === null)
			{
				continue;
			}

			$items[] = $this->crmItemMapper->mapToEntity([
				'id' => $id,
				'entityId' => $entityId,
				'typeId' => $typeId,
				'typeName' => $detail['typeName'],
				'title' => $detail['title'],
				'link' => $detail['url'],
			]);
		}

		return new CrmItemCollection(...$items);
	}
}
