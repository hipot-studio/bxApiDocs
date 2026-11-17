<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Panel\Action;

use Bitrix\Main\Grid\Panel\Action\GroupAction;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Service\Replication\Batch\BatchTemplateActionService;

/**
 * Single group control of the "Regular tasks" section panel (P2.T1, closer to the mockup).
 *
 * By the developer's decision the three former root dropdowns ("Recurrence"/"Edit"/"Delete")
 * are merged into ONE panel element: a flat but meaningfully ordered list of items -
 * pause/resume recurrence, change members (responsible, originator, auditors, accomplices),
 * move to project, delete. The panel dropdown supports only a flat item list (nested subgroups
 * do not pass through the frontend CALLBACK mechanics), so grouping is kept by order. Action ids
 * are {@see BatchTemplateActionService} constants and do not change - the frontend
 * applyGroupAction/setGroupAction needs no edits. Clicks are handled on the frontend via
 * `Replication.Batch.*`.
 */
final class ReplicationGroupAction extends GroupAction
{
	/** Value of the placeholder item the panel dropdown is built with, see GroupAction::getControl(). */
	private const PLACEHOLDER_VALUE = 'default';

	/**
	 * The section renames the platform placeholder of the dropdown ("- actions -") into a prompt.
	 * Only the wording differs: the item value and its reset behaviour stay as the panel built them.
	 */
	public function getControl(): ?array
	{
		$control = parent::getControl();
		if ($control === null)
		{
			return null;
		}

		foreach ($control['ITEMS'] ?? [] as $index => $item)
		{
			if (($item['VALUE'] ?? null) === self::PLACEHOLDER_VALUE)
			{
				$control['ITEMS'][$index]['NAME'] = Loc::getMessage('TASKS_V2_REPLICATION_PANEL_ACTION_PLACEHOLDER');

				break;
			}
		}

		return $control;
	}

	protected function prepareChildItems(): array
	{
		return [
			new PauseAction(),
			new ResumeAction(),
			new BatchChildAction(
				BatchTemplateActionService::ACTION_CHANGE_RESPONSIBLE,
				(string)Loc::getMessage('TASKS_V2_REPLICATION_PANEL_ACTION_CHANGE_RESPONSIBLE'),
			),
			new BatchChildAction(
				BatchTemplateActionService::ACTION_CHANGE_ORIGINATOR,
				(string)Loc::getMessage('TASKS_V2_REPLICATION_PANEL_ACTION_CHANGE_ORIGINATOR'),
			),
			new BatchChildAction(
				BatchTemplateActionService::ACTION_CHANGE_AUDITORS,
				(string)Loc::getMessage('TASKS_V2_REPLICATION_PANEL_ACTION_CHANGE_AUDITORS'),
			),
			new BatchChildAction(
				BatchTemplateActionService::ACTION_CHANGE_ACCOMPLICES,
				(string)Loc::getMessage('TASKS_V2_REPLICATION_PANEL_ACTION_CHANGE_ACCOMPLICES'),
			),
			new BatchChildAction(
				BatchTemplateActionService::ACTION_MOVE_TO_PROJECT,
				(string)Loc::getMessage('TASKS_V2_REPLICATION_PANEL_ACTION_MOVE_TO_PROJECT'),
			),
			new BatchChildAction(
				BatchTemplateActionService::ACTION_DELETE,
				(string)Loc::getMessage('TASKS_V2_REPLICATION_PANEL_ACTION_DELETE'),
			),
		];
	}
}
