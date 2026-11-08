<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Core\Queue\Rule;

use Bitrix\Main\Config\Option;

/**
 * Messages up to the floor were already routed by the previous implementation, which kept its
 * progress in an option instead of the message state.
 *
 * The updater freezes that progress in a floor of its own, but only in the modes allowed to touch
 * module state. The preliminary schema pipeline is not one of them: it adds STATUS with its default
 * of new to the whole history and skips every option write, so the floor can be missing while the
 * history already looks unrouted. Read as a plain option a missing floor would be zero, and the
 * history would be routed - and delivered - a second time.
 */
final class RoutingFloor
{
	public const OPTION_NAME = 'queue_routing_floor_id';

	private const LEGACY_CURSOR_OPTION_NAME = 'queue_last_processed_id';

	/**
	 * A missing floor falls back to the cursor the previous implementation left behind, as it is.
	 * Nothing is derived from the table: it has only grown since the history was frozen, so a floor
	 * capped by the last message id now could name a message that arrived a moment ago - and a floor
	 * names the last message nobody has to route any more, which would strand that one for good.
	 *
	 * A cursor left ahead of the table therefore holds later messages back, exactly as it did before
	 * the routing moved onto the message state. Capping it is a repair of its own and needs a value
	 * read while the history is still untouched, not one read here.
	 */
	public static function get(): int
	{
		$stored = Option::get('calendar', self::OPTION_NAME, '');

		return $stored === ''
			? max(0, (int)Option::get('calendar', self::LEGACY_CURSOR_OPTION_NAME, '0'))
			: (int)$stored;
	}
}
