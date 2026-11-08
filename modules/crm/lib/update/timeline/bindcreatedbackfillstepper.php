<?php

namespace Bitrix\Crm\Update\Timeline;

use Bitrix\Crm\Agent\Timeline\BindCreatedBackfillAgent;
use Bitrix\Main\Update\Stepper;

/**
 * The backfill of b_crm_timeline_bind.CREATED lives in Bitrix\Crm\Agent\Timeline\BindCreatedBackfillAgent now. This
 * class stays only because the released updater of 26.1300.0 has written its name into the schedule of every portal
 * that took that update: the record calls execAgent() of this very class, and nothing else can hand the work over
 * there. It can be deleted once version 26.1300.0 is out of circulation.
 *
 * The step registers the agent and reports the work as finished. The record of the state in the category
 * main.stepper.crm is then removed by the kernel, which also takes this one off the schedule, so no cleanup of its
 * own is needed. The position of the former pass is not carried over: the guard by the empty column makes a repeated
 * range harmless, and the new agent starts from the beginning of the table.
 */
final class BindCreatedBackfillStepper extends Stepper
{
	protected static $moduleId = 'crm';

	public function execute(array &$option): bool
	{
		BindCreatedBackfillAgent::register();

		return self::FINISH_EXECUTION;
	}
}
