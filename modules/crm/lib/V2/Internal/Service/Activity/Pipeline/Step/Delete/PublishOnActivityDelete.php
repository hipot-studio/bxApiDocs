<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EventPublisher;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\ThrowableTransparentStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Announces the deleted activity to the subscribers of the module: row 21 of the owner table STEP-DELETE
 * (crm_activity.php:1517-1521), the last effect of the operation.
 *
 * THIS IS THE EVENT THIRD-PARTY APPLICATIONS SEE, and the one place a delete publishes it from (EVENT-01). The REST
 * webhook `onCrmActivityDelete` is delivered from it, so it stands below every effect of the delete on purpose: what a
 * subscriber reads about is an activity whose row, bindings, communications, statistics, search index and timeline have
 * all been dealt with, and whose provider has already been told (row 20). Anything moved above it would deliver a
 * webhook about a half-deleted activity, and moving it above row 20 would deliver one about an activity its own provider
 * has not seen gone yet.
 *
 * IT IS NOT PUBLISHED WHEN THE ACTIVITY ONLY WENT TO THE RECYCLE BIN, and no condition of this row expresses that: row
 * 5 ends the run with a success of its own where the transfer took the activity away, so this row is simply never
 * reached. A subscriber therefore never learns about a deletion that can still be undone.
 *
 * WHAT THE EVENT CARRIES IS AN IDENTIFIER AND NOTHING ELSE, which is where the shape of a delete differs from the shape
 * of an add and of an update: legacy hands its subscribers the identifier alone, and a subscriber that wants the fields
 * of the gone activity has none to read - exactly as it has been for years. There is no veto and no answer, so the step
 * never fails past its one precondition and never aborts ({@see EventPublisher::publishOnActivityDelete()}).
 *
 * @internal
 */
final class PublishOnActivityDelete implements ThrowableTransparentStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The event names
	 * the activity and nothing else, so there is nothing to announce without it.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private readonly EventPublisher $publisher;

	/**
	 * @param EventPublisher|null $publisher the single publication point of the events of the domain; the one seam of
	 *        the step.
	 */
	public function __construct(?EventPublisher $publisher = null)
	{
		$this->publisher = $publisher ?? new EventPublisher();
	}

	public function getName(): string
	{
		return 'PublishOnActivityDelete';
	}

	/**
	 * Every deletion that got this far is announced. Legacy has no path around this, and no option silences it.
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

		$this->publisher->publishOnActivityDelete($activityId);

		return $result;
	}

	/**
	 * The answers of the subscribers are ignored, so there is nothing here to fail on.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
