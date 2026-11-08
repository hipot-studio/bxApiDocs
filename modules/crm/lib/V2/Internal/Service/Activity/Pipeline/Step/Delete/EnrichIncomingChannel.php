<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Writes the incoming-channel marker into the state the delete works on: row 4 of the owner table STEP-DELETE
 * (crm_activity.php:1290-1292).
 *
 * THE MARKER IS NO COLUMN OF AN ACTIVITY, and that is the whole reason this row exists. Whether somebody reached
 * the company through this activity and has not been answered yet lives in a registry of its own, so the row this
 * pipeline read carries nothing about it - and the rows below need it: the recycle bin stores it in the slot it
 * would restore the activity from, and the record of the timeline and the counters read the same fields. Legacy
 * fills its field array in by hand for exactly that reason, and does it unconditionally.
 *
 * A DELETE HAS ONE STATE TO ENRICH, WHERE AN UPDATE HAS TWO. That is why this is a step of its own and not the one
 * of the update pipeline that bears the same name: there is nothing the operation itself said about the marker to
 * remember - a delete names no fields at all - so the registry is the only answer, no value of the run is left
 * behind, and no exclusive block below reads a decision of this step.
 *
 * @internal
 */
final class EnrichIncomingChannel implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'EnrichIncomingChannel';
	}

	/**
	 * Every delete enriches its state. Legacy has no path around this.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$context->getActivity()->internalSet(
			Activity::isIncomingChannel,
			IncomingChannel::getInstance()->isIncomingChannel($activityId),
		);

		return $result;
	}

	/**
	 * Legacy interrupts a delete on the state it loads, on the restriction of the calls and on the deletion of the
	 * row - and on nothing else.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
