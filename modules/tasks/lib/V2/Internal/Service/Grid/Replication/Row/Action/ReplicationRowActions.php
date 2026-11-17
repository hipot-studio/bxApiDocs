<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Row\Action;

use Bitrix\Main\Grid\Row\Action\DataProvider;

/**
 * Row actions provider for the "Regular tasks" section grid (P1.T1, TPL-D1 contract).
 *
 * Feeds the native grid with the row burger-menu items; each item decides its own
 * visibility by row rights and state ({@see ReplicationRowAction::getControl()}).
 * Clicks are routed to the frontend (`BX.Tasks.V2.Replication.runRowAction`); native
 * `processRequest`/`Panel::processRequest` is not used - grid-ajax is intercepted by the frontend.
 */
final class ReplicationRowActions extends DataProvider
{
	/**
	 * Service row key holding the rights map that is not part of DTO-S1 (P1.T2): it never
	 * reaches TPL-S1 data-attributes and is read only in {@see DeleteRowAction::getControl()}.
	 */
	public const ROW_RIGHTS = 'ROW_RIGHTS';

	public function prepareActions(): array
	{
		return [
			new OpenTemplateRowAction(),
			new EditRepeatRowAction(),
			new PauseRowAction(),
			new ResumeRowAction(),
			new DeleteRowAction(),
		];
	}
}
