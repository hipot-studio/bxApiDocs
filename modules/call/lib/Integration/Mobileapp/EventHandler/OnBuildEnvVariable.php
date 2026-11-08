<?php

declare(strict_types=1);

namespace Bitrix\Call\Integration\Mobileapp\EventHandler;

use Bitrix\Call\Settings;
use Bitrix\Main\EventResult;

/**
 * What the call module tells every screen of the mobile application.
 *
 * The settings of a call travel to the client as parameters of the calls service component and are readable
 * inside that component alone. The call log is a tab component of its own, so the answer "may a guest start a
 * call" would never reach it that way - it comes through the application-wide container instead, next to the
 * guest flag the same screen already reads.
 *
 * The container is assembled for every component, so the handler stays as cheap as an option read.
 */
final class OnBuildEnvVariable
{
	public static function handle(): EventResult
	{
		return new EventResult(
			EventResult::SUCCESS,
			[
				'guestOutgoingCallEnabled' => Settings::isGuestOutgoingCallEnabled(),
				'guestOutgoingPrivateCallEnabled' => Settings::isGuestOutgoingPrivateCallEnabled(),
			],
			'call',
		);
	}
}
