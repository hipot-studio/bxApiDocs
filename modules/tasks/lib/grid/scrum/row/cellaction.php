<?php

namespace Bitrix\Tasks\Grid\Scrum\Row;

use Bitrix\Main\Grid;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class CellAction
 *
 * @package Bitrix\Tasks\Grid\Scrum\Row
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
		$groupId = (int)$this->rowData['ID'];
		$isPinned = ($this->rowData['IS_PINNED'] === 'Y');
		$pinLabel = Loc::getMessage('TASKS_GRID_SCRUM_ROW_CELL_ACTION_PIN');
		$unpinLabel = Loc::getMessage('TASKS_GRID_SCRUM_ROW_CELL_ACTION_UNPIN');

		return [
			'NAME' => [
				[
					'class' => [
						Grid\CellActions::PIN,
						($isPinned ? Grid\CellActionState::ACTIVE : Grid\CellActionState::SHOW_BY_HOVER),
					],
					'attributes' => [
						'role' => 'button',
						'tabindex' => '0',
						'aria-pressed' => ($isPinned ? 'true' : 'false'),
						'aria-label' => ($isPinned ? $unpinLabel : $pinLabel),
						'data-pin-label' => $pinLabel,
						'data-unpin-label' => $unpinLabel,
					],
					'events' => [
						'click' => "BX.Tasks.Projects.ActionsController.changePin.bind(BX.Tasks.Projects.ActionsController, {$groupId})",
						'keydown' => "BX.Tasks.Projects.ActionsController.onPinKeyDown.bind(BX.Tasks.Projects.ActionsController, {$groupId})",
					],
				],
			],
		];
	}
}
