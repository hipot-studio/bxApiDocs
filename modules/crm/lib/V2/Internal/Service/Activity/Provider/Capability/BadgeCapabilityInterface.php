<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;

/**
 * The badges a provider puts on the owners of an activity of its kind: a missed call, an overlapping event, the
 * status of a task, a reaction awaited.
 *
 * THE BINDINGS ARE ALWAYS HANDED OVER AND MAY ALWAYS BE IGNORED. Two of the thirteen implementations that exist
 * ignore them and work by the owner columns of the activity instead, and that is left exactly as it is: an
 * implementation over a legacy provider passes the set on and does not correct what the provider does with it.
 * The set is the one the badges are to be synchronized for - on a change of the bindings it is the added ones,
 * not the whole of them.
 *
 * TAKING BADGES OFF REMOVED BINDINGS IS NOT ASKED OF THE PROVIDER. The step that changes the bindings does it
 * directly, as legacy did, so a provider seeing only additions here is not seeing half a picture: the other
 * half is not its business.
 *
 * WHICH BADGE TO PUT ON IS DECIDED BY THE INPUT, so the context of the operation comes with the activity.
 * Most of the thirteen implementations read what the typed model does not hold: the owner columns, the status
 * of a task and the awaited reaction out of the settings, the parameters of a provider. Handing the columns
 * and the serialized fields over separately would be a second way of expressing the same input in one
 * contract, so the context does it, exactly as in {@see CalendarCapabilityInterface::skipCalendarSync()}.
 *
 * Normative shape: TPL-06. The legacy method, the two final methods that call it and who overrides it: LBS-2
 * sections 2.7 and 4.8, invariant 11 of section 7, checklist item C8.
 *
 * @internal
 */
interface BadgeCapabilityInterface
{
	/**
	 * @param ActivityBinding[] $bindings the owners the badges are to be synchronized for.
	 */
	public function syncBadges(
		int $activityId,
		Activity $activity,
		array $bindings,
		ActivityOperationContext $context
	): void;
}
