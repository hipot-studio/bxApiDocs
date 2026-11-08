<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;

/**
 * What a provider has to say about the reminders of an activity of its kind.
 *
 * NOT IMPLEMENTING THIS MEANS YES, and that is the one place where the absence of a capability is not the
 * quiet answer. A provider that says nothing has its reminder queue synchronized: legacy answered true both
 * from the default of its base class and for an activity with no provider at all, so a provider that wants
 * the queue left alone has to implement this interface and say so. The only one that does unregisters the
 * queue once the activity has a calendar event to remind about it instead.
 *
 * THE DEFAULT OFFSETS ARE ASKED FOR ONLY WHEN NOBODY NAMED ANY. Offsets given by the caller are used as they
 * are, and the provider is not consulted at all - which is why this is not the place to enforce a policy on
 * reminders.
 *
 * THE ANSWER IS READ OFF THE INPUT, so the context of the operation comes with the activity. The only
 * implementation decides by nothing but the identifier of a bound calendar event, and that identifier is a
 * storage column the typed model does not hold ({@see ActivityOperationContext::getInputTransit()}): given
 * the model alone this capability could not answer at all. The same shape as
 * {@see CalendarCapabilityInterface::skipCalendarSync()}, and for the same reason - one way of handing over
 * the input representation, not two.
 *
 * Normative shape: TPL-05. The legacy methods, their call sites and who overrides them: LBS-2 sections 2.8
 * and 4.6, invariant 6 of section 7, checklist item C9.
 *
 * @internal
 */
interface PingCapabilityInterface
{
	/**
	 * The reminder offsets, in minutes before the deadline, for a new activity whose caller named none.
	 *
	 * @param int $entityTypeId the type of the owner the activity is being created for.
	 * @param int|null $categoryId the funnel of that owner, null when it has none.
	 * @return int[]
	 */
	public function getDefaultPingOffsets(int $entityTypeId, ?int $categoryId): array;

	/**
	 * Whether the reminder queue of this activity is to be kept in step with its deadline. A no unregisters
	 * whatever the queue holds for it.
	 *
	 * The activity and the context are the input of the operation, not the stored state.
	 */
	public function needSynchronizePingQueue(Activity $activity, ActivityOperationContext $context): bool;
}
