<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\EventHandler;

use Bitrix\StaffTrack\Internal\Integration\Im\OfficeAccessNotifier;
use Bitrix\StaffTrack\Public\Command\Office\DecideAccessRequest;

/**
 * A button pressed in a confirmation of a request to see the staff of an office.
 *
 * The messenger asks every module about every confirmation, and removes the confirmation of whoever
 * pressed it whatever the answer is. The decider is the user the messenger found the confirmation for,
 * never the author of it: the author is the requester. A press whose decision failed gets the confirmation
 * back, so the request never waits for a decider left without the button; it points at the office when the
 * decision had read the request and its office by then.
 */
class OfficeAccessConfirmHandler
{
	/**
	 * Handles im:OnBeforeConfirmNotify.
	 *
	 * @param mixed $module the module of the confirmation
	 * @param mixed $tag the tag of the confirmation
	 * @param mixed $value the value of the button pressed
	 * @param mixed $notify the fields of the confirmation and RELATION_USER_ID of whoever pressed it
	 * @return string|null null leaves the answer to the messenger, which names the button pressed
	 */
	public static function onBeforeConfirmNotify($module, $tag, $value, $notify): ?string
	{
		if ($module !== OfficeAccessNotifier::MODULE_ID || !is_string($tag))
		{
			return null;
		}

		$request = OfficeAccessNotifier::parseTag($tag);
		if ($request === null)
		{
			return null;
		}

		$command = null;

		try
		{
			$deciderId = is_array($notify) ? (int)($notify['RELATION_USER_ID'] ?? 0) : 0;
			$command = static::createCommand($deciderId);

			$result = $command->execute(
				$request['id'],
				$request['revision'],
				$value === OfficeAccessNotifier::BUTTON_APPROVE,
			);

			if (($result->getData()['decided'] ?? false) === true)
			{
				return null;
			}

			return static::createNotifier()->getAlreadyDecidedText();
		}
		catch (\Throwable $exception)
		{
			static::logFailure(__FUNCTION__, self::describeThrowable($exception));
			self::restoreConfirmation($notify, $command?->getOfficeId() ?? 0);

			return null;
		}
	}

	protected static function createCommand(int $deciderId): DecideAccessRequest
	{
		return new DecideAccessRequest($deciderId);
	}

	protected static function createNotifier(): OfficeAccessNotifier
	{
		return new OfficeAccessNotifier();
	}

	protected static function logFailure(string $operation, string $reason): void
	{
		AddMessage2Log(static::class . '::' . $operation . ' failed: ' . $reason, 'stafftrack');
	}

	/**
	 * Runs inside the confirmation of the messenger, so a failure to send the confirmation back is logged
	 * and stays here as well.
	 */
	private static function restoreConfirmation(mixed $notify, int $officeId): void
	{
		if (!is_array($notify))
		{
			return;
		}

		try
		{
			static::createNotifier()->restoreRequest($notify, $officeId);
		}
		catch (\Throwable $exception)
		{
			static::logFailure('restoreRequest', self::describeThrowable($exception));
		}
	}

	private static function describeThrowable(\Throwable $exception): string
	{
		return $exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine();
	}
}
