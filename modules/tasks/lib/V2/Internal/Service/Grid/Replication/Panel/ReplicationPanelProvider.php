<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Panel;

use Bitrix\Main\Grid\Panel\Action\DataProvider;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Snippet;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Panel\Action\ReplicationGroupAction;
use Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Settings\ReplicationGridSettings;

/**
 * Panel actions provider for the "Regular tasks" section (P4.T3, regrouping P2.T1).
 * Returns only control descriptions; handling is on the frontend via `Replication.Batch.*`.
 *
 * A single root control per the mockup: one dropdown {@see ReplicationGroupAction} with a flat
 * ordered list (recurrence -> change members/move -> delete). The CALLBACK mechanics
 * (setGroupAction/applyGroupAction) and action ids do not change.
 */
final class ReplicationPanelProvider extends DataProvider
{
	public function prepareActions(): array
	{
		return [
			new ReplicationGroupAction(),
		];
	}

	/**
	 * Standard panel composition: the actions dropdown + "Apply" + "for all".
	 * "Apply" and the slice selection are handled on the frontend (grid-ajax is intercepted by
	 * `Grid::beforeRequest`), so the button leads to the JS applier, not Panel::processRequest.
	 *
	 * @return array[]
	 */
	public function prepareControls(): array
	{
		$controls = parent::prepareControls();

		$snippet = new Snippet();

		$controls[] = $snippet->getApplyButton([
			'ONCHANGE' => [
				[
					'ACTION' => Actions::CALLBACK,
					'DATA' => [
						['JS' => "BX.Tasks.V2.Replication.applyGroupAction('" . ReplicationGridSettings::GRID_ID . "')"],
					],
				],
			],
		]);
		$controls[] = $snippet->getForAllCheckbox();

		return $controls;
	}
}
