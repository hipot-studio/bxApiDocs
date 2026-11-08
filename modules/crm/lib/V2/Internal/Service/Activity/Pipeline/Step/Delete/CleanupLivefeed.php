<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\V2\Internal\Service\Activity\Legacy\LivefeedBridge;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;

/**
 * Takes away what the activity stream holds about an erased activity: row 19 of the owner table STEP-DELETE
 * (crm_activity.php:1529-1533) - its log records with the relations built out of them, and every subscription to it.
 *
 * THE GATE IS "THE ACTIVITY REALLY NEVER REACHED THE BIN", AND THAT STATE IS REACHABLE. An activity kept in the recycle
 * bin still has a record of the activity stream to come back to, which is why legacy removes nothing after a transfer.
 * The registry row RECYCLE-ALWAYS-ON states that the setting of the module cannot switch the bin off - and that is the
 * whole of what it states. The gate of legacy is a conjunction: the transfer also needs the module of the bin to be
 * installed at all (`Recycling\ActivityController::isEnabled()`), and it only counts where `moveToBin()` reported a
 * success. A portal without that module and a transfer that failed are therefore two states in which the row of the
 * activity is erased for good, and leaving the records behind would leave them pointing at nothing.
 *
 * THE THREE CAUSES ARE ONE FACT OF THE RUN. Whether row 5 never applied, applied and failed, or never had to run at
 * all - a delete of an activity being erased FROM the bin - what this row is about is the same question, and the run
 * answers it in one place ({@see PipelineContext::hasGoneToTheRecycleBin()}). Row 8 asks it about the files and row 16
 * about the records of the timeline.
 *
 * THE EFFECT ITSELF BELONGS TO THE DYING LAYER AND NOT HERE ({@see LivefeedBridge::removeRecordsOfTheActivity()}).
 * Nothing in V2 produces records of the activity stream or subscriptions to them, so nothing in V2 removes them
 * either: this row owns the gate, and the layer that owns the stream performs the two calls - including the guard
 * legacy has over one of them and not over the other.
 *
 * @internal
 */
final class CleanupLivefeed implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'CleanupLivefeed';
	}

	/**
	 * An activity that went to the recycle bin keeps its records, and a run with no activity has nothing to name in a
	 * removal - see the note of the class.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getActivityId() > 0 && !$context->hasGoneToTheRecycleBin();
	}

	public function execute(PipelineContext $context): Result
	{
		LivefeedBridge::removeRecordsOfTheActivity($context->getActivityId());

		return new Result();
	}

	/**
	 * The activity is already gone by now, and legacy checks nothing about this block.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
