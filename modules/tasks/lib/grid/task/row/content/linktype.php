<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Grid\Task\Row\Content;
use Bitrix\Tasks\V2\Internal\Entity;

class LinkType extends Content
{
	public function prepare(): string
	{
		$linkType = $this->getRowData()['LINK_TYPE'] ?? null;

		return match($linkType)
		{
			Entity\Task\Gantt\LinkType::StartStart => Loc::getMessage('TASKS_GRID_ROW_GANTT_START_START'),
			Entity\Task\Gantt\LinkType::StartFinish => Loc::getMessage('TASKS_GRID_ROW_GANTT_START_FINISH'),
			Entity\Task\Gantt\LinkType::FinishFinish => Loc::getMessage('TASKS_GRID_ROW_GANTT_FINISH_FINISH'),
			default => Loc::getMessage('TASKS_GRID_ROW_GANTT_FINISH_START'),
		};
	}
}
