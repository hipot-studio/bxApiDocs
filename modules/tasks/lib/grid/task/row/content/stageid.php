<?php

namespace Bitrix\Tasks\Grid\Task\Row\Content;

use Bitrix\Main\Web\Json;
use Bitrix\Tasks\Grid\ControlButtonTrait;
use Bitrix\Tasks\Grid\Task\Row\Content;
use Bitrix\Tasks\Kanban\StagesTable;

class StageId extends Content
{
	use ControlButtonTrait;

	public function prepare(): string
	{
		$taskId = (int)($this->getRowDataByName('ID'));
		$stageId = (int)($this->getRowDataByName('STAGE_ID'));
		$groupId = (int)($this->getRowDataByName('GROUP_ID'));

		if (!$groupId || !$taskId)
		{
			return '<span></span>';
		}

		$stages = StagesTable::getGroupStages($groupId, true);

		if (empty($stages))
		{
			return '<span></span>';
		}

		$currentStage = StagesTable::getGroupStageById($stageId, $groupId);
		if (is_null($currentStage))
		{
			return '<span></span>';
		}

		$content = '';

		$this->setParameter('APPLY_CURRENT_STAGE', true);
		$this->setParameter('CURRENT_STAGE', $currentStage);
		$this->setParameter('TASK_ID', $taskId);

		foreach ($stages as $stage)
		{
			$content .= $this->getStageContent($stage);
			if ((int)$currentStage['ID'] === (int)$stage['ID'])
			{
				$this->setParameter('APPLY_CURRENT_STAGE', false);
			}
		}
		$titleContent = $this->getTitleContent($currentStage['TITLE']);

		return "<div class='tasks-grid-stage-wrap'><div class='tasks-grid-stage-container'>{$content}</div>{$titleContent}</div>";
	}

	private function getStageContent(array $stage): string
	{
		$stageId = (int)$stage['ID'];
		$currentStage = $this->getParameter('CURRENT_STAGE');
		$selected = $stageId === (int)$currentStage['ID'] ? 'Y' : 'N';
		$canChangeStage = $this->getParameters()['CAN_SORT_STAGES'] ?? false;

		$style = '';
		if ($this->getParameter('APPLY_CURRENT_STAGE'))
		{
			$style = 'background-color:#' . $currentStage['COLOR'] . ';';
		}

		if ($canChangeStage)
		{
			$taskId = $this->getParameter('TASK_ID');
			$color = Json::encode('#' . $stage['COLOR']);
			$onClick = "BX.PreventDefault(); BX.Tasks.GridActions.onStageSwitch({$taskId}, {$stageId}, {$color});";

			return $this->renderControlButton(
				[
					'class' => 'tasks-grid-stage-step --editable',
					'title' => $stage['TITLE'],
					'aria-label' => $stage['TITLE'],
					'aria-current' => ($selected === 'Y' ? 'step' : ''),
					'data-stage-id' => $stageId,
					'data-selected' => $selected,
					'style' => $style,
				],
				'<div class="tasks-grid-stage-step-btn"></div>',
				$onClick,
				"'"
			);
		}

		$title = htmlspecialcharsbx($stage['TITLE']);
		$styleAttr = $style === '' ? '' : ' style="' . htmlspecialcharsbx($style) . '"';

		return "<div title=\"{$title}\" class=\"tasks-grid-stage-step\" data-stage-id=\"{$stageId}\" data-selected=\"{$selected}\" onclick='BX.PreventDefault();'{$styleAttr}>"
			. '<div class="tasks-grid-stage-step-btn"></div></div>';
	}

	private function getTitleContent(string $title): string
	{
		$title = htmlspecialcharsbx($title);

		return "<div class='tasks-grid-stage-title'>{$title}</div>";
	}
}