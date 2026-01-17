<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\Internals\Task\Template\DependenceTable;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Template;

class ParentTemplateRepository implements ParentTemplateRepositoryInterface
{
	public function getParent(int $templateId): Task|Template|null
	{
		$baseTemplateId = $this->getParentTemplateIds([$templateId])[$templateId] ?? null;
		if ($baseTemplateId !== null)
		{
			return new Template(
				id: $baseTemplateId,
			);
		}

		$row = TemplateTable::query()
			->setSelect(['ID', 'PARENT_ID'])
			->where('ID', $templateId)
			->fetch()
		;

		if (!is_array($row))
		{
			return null;
		}

		$parentId = (int)($row['PARENT_ID'] ?? 0);
		if ($parentId > 0)
		{
			return new Task(
				id: $parentId,
			);
		}

		return null;
	}

	public function getParentTemplateIds(array $templateIds): array
	{
		if (empty($templateIds))
		{
			return [];
		}

		$rows = DependenceTable::query()
			->setSelect(['TEMPLATE_ID', 'PARENT_TEMPLATE_ID'])
			->whereIn('TEMPLATE_ID', $templateIds)
			->fetchAll()
		;

		$result = array_fill_keys($templateIds, null);

		foreach ($rows as $row)
		{
			$templateId = (int)($row['TEMPLATE_ID'] ?? 0);
			$parentId = (isset($row['PARENT_TEMPLATE_ID']) && (int)$row['PARENT_TEMPLATE_ID']) > 0 ? (int)$row['PARENT_TEMPLATE_ID'] : null;
			if ($templateId !== $parentId)
			{
				$result[$templateId] = $parentId;
			}
		}

		return $result;
	}
}
