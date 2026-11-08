<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;

/**
 * Recreating an activity out of the recycle bin, told apart from creating one anew.
 *
 * IT IS NOT A PIPELINE OF ITS OWN. A restoration is an add with three of its effects silenced, so it is a
 * property of the operation asked about by the steps that own those effects - not a second declaration of
 * the add, which would be the copy that goes stale. The three effects are the ones legacy gates on the
 * same flag, and the list is closed at three ({@see self::SUPPRESSED_EFFECTS}):
 * - the body of an email is not bound again, because the row being restored still owns the one it had;
 * - no record of the history of the CRM is written, because the activity is not new to the history;
 * - the timeline is not told about a creation, for the same reason.
 * Everything else an add performs happens on a restoration too: the row, the bindings, the communications,
 * the statistics, the counters, the search index, the events.
 *
 * PROVIDERS LEARN ABOUT IT WITHOUT A CARRIER OF THEIR OWN. Every hook of a provider receives the whole
 * operation context, so a provider that behaves differently on a restoration reads the flag off it - which
 * is why the propagation legacy performs by putting one key into the array it hands each hook needs no code
 * here at all.
 *
 * WHY A NAMED PLACE FOR ONE FLAG. Three steps in three files ask the same question, and the answer is the
 * whole of what "restoration mode" means in the design. Asking through this class is what keeps the list of
 * the silenced effects reviewable in one place: a fourth step silenced on a restoration is a change of the
 * behaviour of the operation, and it has to be visible as one.
 *
 * @internal
 */
final class RestorationMode
{
	/**
	 * The effects of an add a restoration silences, by the name of the step owning each. NORMATIVE and
	 * closed: this is the whole of what the mode changes.
	 */
	public const SUPPRESSED_EFFECTS = [
		'AddMailBodyBinding',
		'WriteHistory',
		'NotifyTimelineOnCreate',
	];

	/**
	 * Whether the operation recreates an activity out of the recycle bin. The carrier of the fact is the
	 * operation context, where the caller put it.
	 */
	public static function isActive(PipelineContext $context): bool
	{
		return $context->getOperationContext()->isRestoration();
	}
}
