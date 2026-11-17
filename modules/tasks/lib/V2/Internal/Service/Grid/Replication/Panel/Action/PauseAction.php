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
 * "Pause recurrence" panel item (P4.T3).
 *
 * The panel returns only the control description; handling goes not through the native
 * Panel::processRequest but through dedicated ajax `Replication.Batch.setReplicateState`
 * (all grid-ajax is intercepted on the frontend) - so processRequest returns null.
 */
final class PauseAction extends GroupChildAction
{
	public const ID = 'replication_pause';

	public static function getId(): string
	{
		return self::ID;
	}

	public function getName(): string
	{
		return (string)Loc::getMessage('TASKS_V2_REPLICATION_PANEL_ACTION_PAUSE');
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
