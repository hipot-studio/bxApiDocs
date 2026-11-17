<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\Replication\Panel;

use Bitrix\Main\Grid\Panel\Panel;

/**
 * Group actions panel of the "Regular tasks" section grid (P4.T3).
 *
 * Having a panel enables row checkboxes and the action panel via
 * {@see \Bitrix\Main\Grid\Component\ComponentParams::get()}. Clicks are handled not through
 * the native Panel::processRequest but through dedicated ajax `Replication.Batch.*`
 * (all grid-ajax is intercepted on the frontend by `Grid::beforeRequest`).
 */
final class ReplicationPanel extends Panel
{
	public function __construct()
	{
		parent::__construct(new ReplicationPanelProvider());
	}
}
