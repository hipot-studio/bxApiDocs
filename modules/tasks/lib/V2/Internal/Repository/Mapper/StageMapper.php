<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Kanban\StagesTable;
use Bitrix\Tasks\V2\Internal\Entity;

class StageMapper
{
	public function mapToCollection(array $stages): Entity\StageCollection
	{
		$result = [];
		foreach ($stages as $stage)
		{
			$result[] = $this->mapToEntity($stage);
		}

		return new Entity\StageCollection(...$result);
	}

	public function mapToEntity(array $stage): Entity\Stage
	{
		$title = $stage['TITLE'] ?? null;
		if (empty($title))
		{
			Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/kanban/stages.php');
			$systemType = $stage['SYSTEM_TYPE'] ?? StagesTable::SYS_TYPE_DEFAULT;
			$title = Loc::getMessage('TASKS_STAGE_' . $systemType);
		}

		$color = $stage['COLOR'] ?? null;
		if (empty($color))
		{
			$color = StagesTable::DEF_COLOR_STAGE;
		}

		return new Entity\Stage(
			id: isset($stage['ID']) ? (int)$stage['ID'] : null,
			title: $title,
			color: $color,
			systemType: $stage['SYSTEM_TYPE'],
			sort: (int)($stage['SORT'] ?? 0),
		);
	}
}
