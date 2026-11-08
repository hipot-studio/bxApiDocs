<?php
namespace Bitrix\Tasks\Grid\Task\Row;

use Bitrix\Main\Grid;
use Bitrix\Main\Localization\Loc;

/**
 * Class CellAction
 *
 * @package Bitrix\Tasks\Grid\Task\Row
 */
class CellAction
{
	protected $rowData = [];
	protected $parameters = [];

	/**
	 * Action constructor.
	 *
	 * @param array $rowData
	 * @param array $parameters
	 */
	public function __construct(array $rowData = [], array $parameters = [])
	{
		$this->rowData = $rowData;
		$this->parameters = $parameters;
	}

	/**
	 * @return \array[][]
	 */
	public function prepare(): array
	{
		$taskId = $this->rowData['ID'];

		$cellActions = [
			'TITLE' => [],
		];

		if ($this->parameters['CAN_USE_PIN'])
		{
			if ($groupId = $this->parameters['GROUP_ID'])
			{
				$isPinned = (
					array_key_exists('IS_PINNED_IN_GROUP', $this->rowData)
					&& $this->rowData['IS_PINNED_IN_GROUP'] === 'Y'
				);
			}
			else
			{
				$isPinned = ($this->rowData['IS_PINNED'] === 'Y');
			}

			$cellActions['TITLE'][] = [
				'class' => [
					Grid\CellActions::PIN,
					($isPinned ? Grid\CellActionState::ACTIVE : Grid\CellActionState::SHOW_BY_HOVER),
				],
				'attributes' => [
					'aria-label' => Loc::getMessage(
						$isPinned
							? 'TASKS_GRID_TASK_ROW_CELL_ACTION_UNPIN_MSGVER_1'
							: 'TASKS_GRID_TASK_ROW_CELL_ACTION_PIN_MSGVER_1'
					),
				],
				'events' => [
					'click' => "BX.Tasks.GridActions.changePin.bind(BX.Tasks.GridActions, {$taskId}, {$groupId})",
				],
			];
		}

		$cellActions['TITLE'][] = [
			'class' => [
				Grid\CellActions::MUTE,
				(($this->rowData['IS_MUTED'] ?? '') === 'Y' ? Grid\CellActionState::ACTIVE : Grid\CellActionState::SHOW_BY_HOVER),
			],
			'attributes' => [
				'aria-label' => Loc::getMessage(
					($this->rowData['IS_MUTED'] ?? '') === 'Y'
						? 'TASKS_GRID_TASK_ROW_CELL_ACTION_UNMUTE_MSGVER_1'
						: 'TASKS_GRID_TASK_ROW_CELL_ACTION_MUTE_MSGVER_1'
				),
			],
			'events' => [
				'click' => "BX.Tasks.GridActions.changeMute.bind(BX.Tasks.GridActions, {$taskId})",
			],
		];

		return $cellActions;
	}
}