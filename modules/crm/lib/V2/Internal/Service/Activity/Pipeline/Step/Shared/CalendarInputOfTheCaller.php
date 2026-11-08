<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Integration\Calendar\ActivityCalendarSync;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;

/**
 * What the caller said about the calendar event of an activity, in the names the calendar layer accepts.
 *
 * ONE TRANSLATION FOR TWO STEPS. The keys arrive under the names legacy gives them, because the operation context
 * carries the representation the caller gave, and the layer names them its own way; both the step of an add and the
 * step of an update need the same correspondence, and a copy of it in each would be the copy that goes stale. It
 * lives beside the other small shared statements of the pipeline for the same reason those do.
 *
 * A KEY THE CALLER DID NOT NAME IS LEFT OUT rather than handed over empty: the layer tells a value that was given
 * from one that was not - a meeting nobody mentioned is not a meeting refused - and it refuses a key that is not
 * one of its own, so the correspondence has to be complete and closed.
 *
 * TWO GROUPS, BECAUSE LEGACY CONSUMES THEM DIFFERENTLY. The calendar block of legacy takes four of these keys OUT
 * of the field array it was handed as it reads them, and the reverse projection of the facade reproduces exactly
 * that ({@see self::legacyKeys()}). The blocks the description of the event is enriched with are read by the check
 * of the fields of the provider instead, which leaves the key where it found it - so translating that one must not
 * make the facade start removing a key legacy has always left behind.
 *
 * @internal
 */
final class CalendarInputOfTheCaller
{
	/** The name the layer knows each legacy key by, for the keys the calendar block of legacy takes out. */
	private const INPUTS_BY_REMOVED_LEGACY_KEY = [
		'SECTION_ID' => ActivityCalendarSync::INPUT_SECTION_ID,
		'ATTENDEES_CODES' => ActivityCalendarSync::INPUT_ATTENDEES_CODES,
		'IS_MEETING' => ActivityCalendarSync::INPUT_IS_MEETING,
		'UF_CRM_CAL_EVENT' => ActivityCalendarSync::INPUT_BOUND_ENTITY_VALUES,
	];

	/** The same, for the keys legacy reads and leaves in the field array of its caller. */
	private const INPUTS_BY_KEPT_LEGACY_KEY = [
		'CALENDAR_ADDITIONAL_DESCRIPTION_DATA' => ActivityCalendarSync::INPUT_ADDITIONAL_DESCRIPTION_DATA,
	];

	/**
	 * @return array<string, mixed> the `INPUT_*` keys of the layer the operation really named.
	 */
	public static function of(ActivityOperationContext $operationContext): array
	{
		$given = $operationContext->getCalendarInput();

		$input = [];
		foreach (self::INPUTS_BY_REMOVED_LEGACY_KEY + self::INPUTS_BY_KEPT_LEGACY_KEY as $legacyKey => $inputKey)
		{
			if (array_key_exists($legacyKey, $given))
			{
				$input[$inputKey] = $given[$legacyKey];
			}
		}

		return $input;
	}

	/**
	 * The legacy names of the keys the calendar block of legacy takes out, which is what the reverse projection of
	 * the facade takes out of the field array of its caller once this input has been consumed. The keys legacy
	 * leaves in place are none of its business.
	 *
	 * @return string[]
	 */
	public static function legacyKeys(): array
	{
		return array_keys(self::INPUTS_BY_REMOVED_LEGACY_KEY);
	}
}
