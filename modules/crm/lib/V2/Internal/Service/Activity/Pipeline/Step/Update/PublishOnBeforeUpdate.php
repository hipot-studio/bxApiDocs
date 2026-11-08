<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EventPublisher;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\LegacyFieldsOfTheSubscribers;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\ThrowableTransparentStepInterface;
use Bitrix\Main\Result;

/**
 * Tells the subscribers of the module that an activity is about to change: row 11 of the owner table of an update
 * (crm_activity.php:649-653).
 *
 * THE MOMENT IS THE INVERSION OF THE ONE AN ADD PUBLISHES AT, AND IT IS A CONTRACT (item D5, LBS-1 section 13
 * item 2). An add publishes before the description of a letter is compressed and before the emoji are escaped; an
 * update publishes after both. So a subscriber of an update sees the preview of a letter rather than its body and
 * the escaped shape of every emoji rather than the characters - deliberately, bug for bug (item D14) - and the
 * order 9 -> 10 -> 11 is as much an invariant of the design as the order 4 -> 6 -> 7 of an add.
 *
 * WHAT THE ARRAY DOES NOT CARRY IS AS MUCH THE CONTRACT AS WHAT IT DOES. By this point the check of the fields of
 * an update has taken away the four values an update never writes - the author of the activity, its legacy type,
 * the identity of its provider and a deadline of the caller - and the identity of the provider has come back with
 * the value of the stored row. A subscriber therefore sees no AUTHOR_ID, no TYPE_ID and no DEADLINE, and sees the
 * provider the row already belongs to; legacy shows exactly that, because it strips the same keys from the same
 * array. The identifier of the activity is not there either: legacy drops it just above this point (:616-619), so
 * a subscriber reads it off the first argument of the event and never off the fields.
 *
 * THERE IS NO VETO AND NO ANSWER. Whatever a subscriber returns is ignored, false included: an update cannot be
 * refused here, only by the check of the fields above it. So the step never fails and never aborts.
 *
 * THE SHAPE OF THE ARRAY AND THE READING OF THE ANSWER BELONG TO ONE RULE, and the step of an add publishes by
 * the same one: what goes into the array, what deliberately stays out of it, and what a value a subscriber
 * changed, added or took away does to the run are all stated in {@see LegacyFieldsOfTheSubscribers}. What is of
 * an update rather than of a publication is named there as well - the identifier is left out, and an answer takes
 * one step more than on an add, because an update writes only the fields marked as changed.
 *
 * @internal
 */
final class PublishOnBeforeUpdate implements ThrowableTransparentStepInterface
{
	private readonly EventPublisher $publisher;
	private readonly LegacyFieldsOfTheSubscribers $legacyFields;

	/**
	 * @param EventPublisher|null $publisher the single publication point of the events of the domain; the one seam
	 *        of the step.
	 */
	public function __construct(?EventPublisher $publisher = null)
	{
		$this->publisher = $publisher ?? new EventPublisher();
		$this->legacyFields = LegacyFieldsOfTheSubscribers::ofAnUpdate();
	}

	public function getName(): string
	{
		return 'PublishOnBeforeUpdate';
	}

	/**
	 * Every update is published. Legacy has no path around this.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$fields = $this->legacyFields->of($context);
		$asPublished = $fields;

		$this->publisher->publishOnBeforeUpdate($context->getActivityId(), $fields);

		$this->legacyFields->applyWhatSubscribersChanged($context, $asPublished, $fields);

		return new Result();
	}

	/**
	 * The answers of the subscribers are ignored, so there is nothing here to fail on.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
