<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EventPublisher;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\LegacyFieldsOfTheSubscribers;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\ThrowableTransparentStepInterface;
use Bitrix\Main\Result;

/**
 * Tells the subscribers of the module that an activity is about to be created: step 6 of the owner table of an
 * add (crm_activity.php:146-150), checklist item C4.
 *
 * THE MOMENT IS THE CONTRACT, AND IT IS THE REASON THIS STEP STANDS WHERE IT DOES. A subscriber sees the values
 * one write away from the row, with two exceptions it is entitled to: the settings of the provider serialized
 * already, and the description of a letter not compressed yet (LBS-1 section 13 item 1). The two are not a
 * coincidence of the legacy code but the shape its subscribers read for years - the serialization happens above
 * this step and the compression below it - so the order 4 -> 6 -> 7 is an invariant of the design and the test
 * of the composition of the pipeline pins it.
 *
 * THERE IS NO VETO AND NO ANSWER. Whatever a subscriber returns is ignored, false included: an add cannot be
 * refused here, only by the check of the fields above and by the insert below. So the step never fails and
 * never aborts.
 *
 * THE SHAPE OF THE ARRAY AND THE READING OF THE ANSWER BELONG TO ONE RULE, and the step of an update publishes
 * by the same one: what goes into the array, what deliberately stays out of it, and what a value a subscriber
 * changed, added or took away does to the run are all stated in {@see LegacyFieldsOfTheSubscribers}. What is of
 * an add rather than of a publication is named there as well - the identifier is published, and a field the
 * answer changed needs no mark, because an add writes every field that was assigned.
 *
 * @internal
 */
final class PublishOnBeforeAdd implements ThrowableTransparentStepInterface
{
	private readonly EventPublisher $publisher;
	private readonly LegacyFieldsOfTheSubscribers $legacyFields;

	/**
	 * @param EventPublisher|null $publisher the single publication point of the events of the domain; the one
	 *        seam of the step.
	 */
	public function __construct(?EventPublisher $publisher = null)
	{
		$this->publisher = $publisher ?? new EventPublisher();
		$this->legacyFields = LegacyFieldsOfTheSubscribers::ofAnAdd();
	}

	public function getName(): string
	{
		return 'PublishOnBeforeAdd';
	}

	/**
	 * Every add is published. Legacy has no path around this.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$fields = $this->legacyFields->of($context);
		$asPublished = $fields;

		$this->publisher->publishOnBeforeAdd($fields);

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
