<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Panel\Action;

use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Action\Group\GroupChildAction;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * "Resume recurrence" panel item (P4.T3).
 *
 * Handling goes through `Replication.Batch.setReplicateState` (intercepted on the frontend),
 * so processRequest returns null. "Resume" looks only forward, without backfill (AC-S-040) -
 * the logic lives in BatchReplicateStateService.
 */
final class ResumeAction extends GroupChildAction
{
	public const ID = 'replication_resume';

	public static function getId(): string
	{
		return self::ID;
	}

	public function getName(): string
	{
		return (string)Loc::getMessage('TASKS_V2_REPLICATION_PANEL_ACTION_RESUME');
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		return null; // handled in Replication.Batch.setReplicateState
	}

	protected function getOnchange(): Onchange
	{
		// Remember the current action on the frontend; execution happens on "Apply".
		return new Onchange([
			[
				'ACTION' => Actions::CALLBACK,
				'DATA' => [
					['JS' => "BX.Tasks.V2.Replication.setGroupAction('" . self::ID . "')"],
				],
			],
		]);
	}
}
