<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Main\DB\SqlException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Internals\Task\Template\TemplateDependenceTable;

class RelatedTaskTemplateRepository implements RelatedTaskTemplateRepositoryInterface
{
	public function getRelatedTaskIds(int $templateId): array
	{
		$rows = TemplateDependenceTable::query()
			->setSelect(['DEPENDS_ON_ID'])
			->where('TEMPLATE_ID', $templateId)
			->fetchAll()
		;

		$ids = array_column($rows, 'DEPENDS_ON_ID');

		Collection::normalizeArrayValuesByInt($ids, false);

		return $ids;
	}

	public function containsRelatedTasks(int $templateId): bool
	{
		$result = TemplateDependenceTable::query()
			->setSelect([new ExpressionField('EXISTS', 1)])
			->where('TEMPLATE_ID', $templateId)
			->fetch()
		;

		return $result !== false;
	}

	public function save(int $templateId, array $relatedTaskIds): void
	{
		Collection::normalizeArrayValuesByInt($relatedTaskIds, false);
		if (empty($relatedTaskIds))
		{
			return;
		}

		$rows = [];
		foreach ($relatedTaskIds as $relatedTaskId)
		{
			$rows[] = [
				'TEMPLATE_ID' => $templateId,
				'DEPENDS_ON_ID' => $relatedTaskId,
			];
		}

		$result = TemplateDependenceTable::addInsertIgnoreMulti($rows);
		if (!$result->isSuccess())
		{
			throw new SqlException($result->getError()?->getMessage());
		}
	}

	public function deleteByRelatedTaskIds(int $templateId, array $relatedTaskIds): void
	{
		Collection::normalizeArrayValuesByInt($relatedTaskIds, false);
		if (empty($relatedTaskIds))
		{
			return;
		}

		TemplateDependenceTable::deleteByFilter(['TEMPLATE_ID' => $templateId, 'DEPENDS_ON_ID' => $relatedTaskIds]);
	}
}
