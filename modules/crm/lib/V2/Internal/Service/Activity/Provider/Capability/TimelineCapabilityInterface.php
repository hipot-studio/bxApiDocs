<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Timeline\TimelineItemRequest;

/**
 * How an activity of this provider takes part in the timeline: whether it belongs there at all, whether the
 * timeline controller serves it, and what its card is.
 *
 * THREE QUESTIONS, NOT ONE. The two lists legacy kept do not hold the same providers: the one deciding whether
 * an activity belongs in the timeline has the providers of a delivery, of a tracked call, of a stock document,
 * of a payment and of a booking; the one the controller asks has instead the providers of the messages and of a
 * visit. Whether that is a defect nobody can say from the outside, so it is conserved rather than levelled: a
 * provider answers both questions separately and may answer them differently.
 *
 * A CARD IS NOT ALWAYS THE PROVIDER'S OWN. An activity whose provider builds no card is still shown - the
 * timeline falls back to the cards that render any activity generically. That fallback is what the absent
 * answer means, so building nothing is an ordinary answer here and not a failure.
 *
 * Normative shape: TPL-10. The two lists and the factory of cards: LBS-2 section 3, maps Nr6, Nr7 and Nr8,
 * checklist item B4. Which provider belongs to which map is fixed by DTO-03, not here.
 *
 * @internal
 */
interface TimelineCapabilityInterface
{
	/**
	 * Whether an activity of this provider belongs in the timeline at all (map Nr6).
	 */
	public function isTimelineSupported(): bool;

	/**
	 * Whether the timeline controller serves an activity of this provider (map Nr7).
	 */
	public function isSupportedByTimelineController(): bool;

	/**
	 * The card of one activity of this provider (map Nr8), or null when the provider builds none of its own and
	 * the generic cards are to render it.
	 *
	 * The card is an instance of {@see \Bitrix\Crm\Service\Timeline\Item}: a type of the timeline layer of this
	 * very module, which no integration layer stands between.
	 */
	public function createTimelineItem(TimelineItemRequest $request): ?object;
}
