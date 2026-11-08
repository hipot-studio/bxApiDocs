<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\WorkflowTemplate;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\ORM\Data\UpdateResult;

class WorkflowTemplateRepository
{
	public function updateTemplate(int $id, array $data): UpdateResult
	{
		return WorkflowTemplateTable::update($id, $data);
	}

	/**
	 * Physical row-presence check. Intentionally broader than the business-level template lookups
	 * that exclude SYSTEM_CODE templates: this only disambiguates a 0-affected-rows update between a
	 * genuine no-op on an existing row and a deleted row, so a system template must count as existing.
	 */
	public function exists(int $id): bool
	{
		return (bool)WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->where('ID', $id)
			->setLimit(1)
			->fetch()
		;
	}

	/**
	 * @param int[] $ids
	 * @return int[] the ids among $ids that have a row, same physical presence as exists()
	 */
	public function filterExisting(array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$rows = WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $ids)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	public function isTemplateActive(int $templateId): ?bool
	{
		$templateRow = WorkflowTemplateTable::query()
			->setSelect(['ACTIVE'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		if (!$templateRow)
		{
			return null;
		}

		return $templateRow['ACTIVE'] === 'Y';
	}
}
