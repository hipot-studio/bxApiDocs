<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Main\Config\Option;

/**
 * Rollout flag of the online booking nodes shipped by the booking module: their descriptions stay
 * excluded from every catalog until the option is on. Booking and bizproc arrive in independent
 * updates, so the node descriptions check the presence of this class before reading it.
 */
final class BookingNodesFeature
{
	public const MODULE_ID = 'bizproc';
	public const OPTION_NAME = 'booking_nodes_available';

	private const DEFAULT_VALUE = 'N';

	public static function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, self::DEFAULT_VALUE) === 'Y';
	}
}
