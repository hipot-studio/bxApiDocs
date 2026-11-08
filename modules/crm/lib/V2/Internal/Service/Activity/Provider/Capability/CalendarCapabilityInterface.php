<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;

/**
 * The part a provider takes in keeping an activity of its kind synchronized with an event of a calendar.
 *
 * FIVE PREDICATES, NOT ONE. They are asked at different moments of different operations, and legacy kept them
 * apart for that reason. A delete asks whether calendar events are used at all; an add and an update ask
 * whether one may be created; the completed branch of both asks whether a completed activity keeps its event;
 * a reassigned event is asked about when the event has moved. Merging any two changes what happens to the
 * events of the providers that answer differently - a call uses calendar events but creates them only while
 * the corresponding setting of the portal is on, so the wider answer may not stand in for the narrower one.
 *
 * THE FIELDS THE SYNCHRONIZATION IS JUDGED BY ARE THE GIVEN ONES. {@see self::skipCalendarSync()} sees the
 * activity as the caller handed it over, together with the columns and the serialized fields of the same
 * input ({@see ActivityOperationContext}), and never a snapshot re-read from storage. The one implementation
 * that exists decides by the identifier of an already bound calendar event and by the section kept in the
 * settings, and both are values of the input: reading them from a re-read row would answer about the state
 * before the operation instead of the state being written.
 *
 * THE FOUR PREDICATES ANSWER ABOUT ONE KIND OF ACTIVITY, NOT ABOUT THE PROVIDER AS A WHOLE, which is why
 * each of them is given the provider type. Two providers answer differently for their own types: calls have
 * calendar events only for the call type and not for a callback, and conferences only for the type that
 * starts one. The registry hands out one object per provider and keeps it for the whole hit, so a predicate
 * with nothing to answer about would have to answer for every type at once, and for those two it would
 * answer wrongly - a callback would get an event legacy never made for it, or an ordinary call would stop
 * getting the one it needs. An unnamed type is the legacy default and means "no type given", not "any type".
 *
 * A PROVIDER WITHOUT A CALENDAR DOES NOT IMPLEMENT THIS INTERFACE, and about thirty of them are in that
 * state. There is nothing neutral to answer instead: no capability means the calendar block of the pipeline
 * is skipped whole, which is exactly what the defaults of `Provider\Base` amount to.
 *
 * Normative shape: TPL-02. The legacy predicates and who overrides them: LBS-2 sections 2.5 and 4.4,
 * invariant 4 of section 7, checklist items C5 and C6.
 *
 * @internal
 */
interface CalendarCapabilityInterface
{
	/**
	 * Whether activities of the given type have calendar events at all. Asked by a delete, and it is the
	 * wider question of the two: an event may be used and still never be created by CRM.
	 */
	public function canUseCalendarEvents(?string $providerTypeId = null): bool;

	/**
	 * Whether an event may be created or updated for an activity of the given type. Asked by an add and by
	 * an update, and never in place of {@see self::canUseCalendarEvents()}.
	 */
	public function canAddCalendarEvents(?string $providerTypeId = null): bool;

	/**
	 * Whether the event of a completed activity stays in the calendar. Asked in the completed branch of an
	 * add and of an update; a refusal removes the event once the activity is done.
	 */
	public function canKeepCompletedInCalendar(?string $providerTypeId = null): bool;

	/**
	 * Whether an event that has moved to another calendar keeps its previous copy. A refusal deletes the
	 * event left behind.
	 */
	public function canKeepReassignedInCalendar(?string $providerTypeId = null): bool;

	/**
	 * Whether this operation writes nothing to the calendar, even though events are allowed.
	 *
	 * The activity and the context are the input of the operation, not the stored state.
	 */
	public function skipCalendarSync(Activity $activity, ActivityOperationContext $context): bool;
}
