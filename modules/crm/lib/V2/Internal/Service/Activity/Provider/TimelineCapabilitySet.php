<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

/**
 * Which of the three timeline questions a provider answers affirmatively, as its descriptor declares it.
 *
 * DECLARATION, NOT BEHAVIOUR, like everything else a descriptor holds. The composition of the two lists is
 * wanted without building three dozen providers first, and a provider wrapped from a legacy class has nothing
 * of its own to answer the two predicates with - the declaration is where its answers come from. Building the
 * card is not declarable and stays on the provider object.
 *
 * ONE SET RATHER THAN THREE MORE ARGUMENTS. A descriptor already takes nine; three loose flags would make
 * twelve, and the three belong together anyway - they are what one provider says about one subsystem.
 *
 * DECLARING NOTHING IS AN ANSWER. Roughly a quarter of the providers appear in none of the three maps, and for
 * them "no timeline participation" is the permanent and correct state rather than an unfinished one. So a
 * descriptor that names no set is read exactly as a descriptor declaring all three answers negative, and no
 * reader of a set has to tell one from the other.
 *
 * Which provider answers what is fixed by DTO-03 and not here.
 *
 * @see Capability\TimelineCapabilityInterface for the questions themselves.
 *
 * @internal
 */
final class TimelineCapabilitySet
{
	public function __construct(
		private readonly bool $isTimelineSupported = false,
		private readonly bool $isSupportedByTimelineController = false,
		private readonly bool $providesTimelineItem = false,
	)
	{
	}

	/**
	 * Whether an activity of the provider belongs in the timeline at all (map Nr6).
	 */
	public function isTimelineSupported(): bool
	{
		return $this->isTimelineSupported;
	}

	/**
	 * Whether the timeline controller serves an activity of the provider (map Nr7).
	 */
	public function isSupportedByTimelineController(): bool
	{
		return $this->isSupportedByTimelineController;
	}

	/**
	 * Whether the provider builds a card of its own instead of leaving an activity to the generic ones
	 * (map Nr8).
	 */
	public function providesTimelineItem(): bool
	{
		return $this->providesTimelineItem;
	}
}
