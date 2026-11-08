<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content;

use Bitrix\Tasks\Grid\ControlButtonTrait;
use Bitrix\Tasks\Grid\Task\Row\Action;
use Bitrix\Tasks\Grid\Task\Row\Content;
use Bitrix\Tasks\Internals\Task\Status;
use CTasks;

/**
 * Class CompleteFlag
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content
 */
class CompleteFlag extends Content
{
	use ControlButtonTrait;

	/**
	 * @return string
	 */
	public function prepare(): string
	{
		$row = $this->getRowData();
		$taskId = (int)$row['ID'];

		if ($row['ACTION']['COMPLETE'])
		{
			$checkListArgs = Action::getCheckListJsArgs($row);
			$name = GetMessage('TASKS_GRID_TASK_ROW_CONTENT_COMPLETE_FLAG_CLOSE_TASK');

			return $this->renderControlButton(
				[
					'class' => 'task-complete-action task-complete-action-need-complete',
					'aria-label' => $name,
					'title' => $name,
				],
				'',
				"event.stopPropagation(); BX.Tasks.GridActions.action('complete', {$taskId}, {$checkListArgs});"
			);
		}

		if ($row['REAL_STATUS'] === Status::COMPLETED)
		{
			$name = htmlspecialcharsbx(GetMessage('TASKS_GRID_TASK_ROW_CONTENT_COMPLETE_FLAG_FINISHED'));

			return "<span class=\"task-complete-action task-complete-action-completed\" role=\"img\" aria-label=\"{$name}\" title=\"{$name}\" onclick=\"event.stopPropagation();\"></span>";
		}

		return '';
	}
}
