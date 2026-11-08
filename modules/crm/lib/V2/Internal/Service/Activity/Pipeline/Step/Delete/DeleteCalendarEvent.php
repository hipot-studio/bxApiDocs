<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Integration\Calendar\ActivityCalendarSync;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\CalendarCapabilityInterface;
use Bitrix\Main\Result;

/**
 * Takes the event of the calendar of the deleted activity away: row 14 of the owner table STEP-DELETE
 * (crm_activity.php:1468-1476), checklist item G1.3.
 *
 * THE PREDICATE OF THE PROVIDER IS THE WIDER ONE, AND THAT IS THE WHOLE POINT OF THE ROW (item E8, invariant 4 of
 * section 7 of LBS-2). An add and an update ask whether an event MAY BE CREATED for an activity of this kind
 * ({@see CalendarCapabilityInterface::canAddCalendarEvents()}); a delete asks whether events are USED at all
 * ({@see CalendarCapabilityInterface::canUseCalendarEvents()}), and the two answers part company for a real provider:
 * a call uses calendar events and creates them only while the corresponding setting of the portal is on. Asking the
 * narrower one here would leave the events of every portal with that setting off behind, orphaned by the activity
 * they belonged to.
 *
 * THE DEMAND OF THE CALLER STILL APPLIES (item G1.3). Legacy nests it inside the predicate of the provider (:1470),
 * so an operation that switched the calendar off leaves the event alone - which is what the edit coming out of the
 * calendar itself passes, and the reason the event is not removed twice.
 *
 * THE EVENT IS ONLY MARKED DELETED, not erased, and the reverse direction is suppressed while it happens - both are
 * rules of the calendar layer ({@see ActivityCalendarSync::deleteEvent()}) and neither is a decision of this step.
 * The identifier of the event is the column of the loaded state; an activity that never had an event is a call the
 * layer answers with nothing, exactly as legacy answers it (:7300-7303).
 *
 * NOTHING IS WRITTEN BACK. Unlike the completed branch of an update, which zeroes the column naming the event, a
 * delete leaves no row to write into - row 8 erased it.
 *
 * @internal
 */
final class DeleteCalendarEvent implements PipelineStepInterface
{
	private const COLUMN_CALENDAR_EVENT_ID = 'CALENDAR_EVENT_ID';

	private readonly ActivityCalendarSync $calendar;

	/**
	 * @param ActivityCalendarSync|null $calendar the calendar layer of an activity; the one seam of the step.
	 */
	public function __construct(?ActivityCalendarSync $calendar = null)
	{
		$this->calendar = $calendar ?? new ActivityCalendarSync();
	}

	public function getName(): string
	{
		return 'DeleteCalendarEvent';
	}

	/**
	 * The gate of legacy in its order: the provider takes part in calendars at all, activities of this kind have
	 * events, and the caller did not switch the calendar off. See the note of the class on the predicate.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		$provider = $context->getProvider();
		if (!$provider instanceof CalendarCapabilityInterface)
		{
			return false;
		}

		if (!$provider->canUseCalendarEvents($context->getActivity()->getProviderTypeId()))
		{
			return false;
		}

		return !$context->getOperationContext()->shouldSkipCalendarEvent();
	}

	public function execute(PipelineContext $context): Result
	{
		$this->calendar->deleteEvent(self::eventIdOf($context->getTransit()));

		return new Result();
	}

	/**
	 * An activity whose event could not be taken away is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The event of the activity as the column of the loaded state names it, or nothing where it named none.
	 */
	private static function eventIdOf(StorageFieldTransit $transit): int
	{
		return $transit->has(self::COLUMN_CALENDAR_EVENT_ID)
			? (int)$transit->get(self::COLUMN_CALENDAR_EVENT_ID)
			: 0
		;
	}
}
