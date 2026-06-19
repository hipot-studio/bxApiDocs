<?php

namespace Bitrix\TasksMobile\Service\Template;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Tasks\Internals\Task\Template\CheckListTable;

final class TemplateChecklistCounterService
{
	/**
	 * @param int[] $templateIds
	 * @return array<int, array{completed:int, uncompleted:int}>
	 */
	public function getCountersByTemplateIds(array $templateIds): array
	{
		$templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds))));
		if (empty($templateIds))
		{
			return [];
		}

		$rows = CheckListTable::query()
			->setSelect([
				'TEMPLATE_ID',
				new ExpressionField('TOTAL_CNT', 'COUNT(*)'),
				new ExpressionField('COMPLETED_CNT', "SUM(CASE WHEN %s = 1 THEN 1 ELSE 0 END)", ['CHECKED']),
			])
			->whereIn('TEMPLATE_ID', $templateIds)
			->setGroup(['TEMPLATE_ID'])
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$templateId = (int)$row['TEMPLATE_ID'];
			$total = (int)$row['TOTAL_CNT'];
			$completed = (int)$row['COMPLETED_CNT'];

			if ($templateId <= 0 || $total <= 0)
			{
				continue;
			}

			$result[$templateId] = [
				'completed' => max(0, $completed),
				'uncompleted' => max(0, $total - $completed),
			];
		}

		return $result;
	}
}

