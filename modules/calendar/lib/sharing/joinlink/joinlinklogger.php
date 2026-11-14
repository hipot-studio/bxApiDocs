<?php

namespace Bitrix\Calendar\Sharing\JoinLink;

use Bitrix\Main\Diag\EventLogger;

/**
 * Fail-soft logger for the guest-chat join-link branch.
 *
 * Writes only identifiers and the error type to the module event log under a
 * dedicated audit marker, so calendar owners can filter guest-chat failures.
 * It never logs the guest URL, token/code, email or phone, never throws and
 * never changes the join-link result.
 */
final class JoinLinkLogger
{
	private const MODULE_ID = 'calendar';
	private const AUDIT_TYPE = 'SHARING_JOIN_LINK_GUEST_CHAT';

	private const ALLOWED_KEYS = [
		'eventId',
		'eventLinkId',
		'ownerId',
		'hostId',
		'branch',
		'step',
		'errorCode',
	];

	public function logFailure(array $context): void
	{
		try
		{
			$message = $this->buildMessage($context);
			if ($message === '')
			{
				return;
			}

			(new EventLogger(self::MODULE_ID, self::AUDIT_TYPE))->error($message);
		}
		catch (\Throwable)
		{
			// logging must never break the booking flow
		}
	}

	private function buildMessage(array $context): string
	{
		$parts = [];
		foreach (self::ALLOWED_KEYS as $key)
		{
			if (!array_key_exists($key, $context))
			{
				continue;
			}

			$value = $context[$key];
			if ($value === null || is_scalar($value))
			{
				$parts[] = $key . '=' . ($value ?? '');
			}
		}

		return implode(', ', $parts);
	}
}
