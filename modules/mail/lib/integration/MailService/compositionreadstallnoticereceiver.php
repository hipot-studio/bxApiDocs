<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Throwable;

final class CompositionReadStallNoticeReceiver
{
	public const REASONS = ['access_refused', 'operation_disabled'];
	public const ERROR_CONTRACT_INVALID = 'MAIL_COMPOSITION_STALL_CONTRACT_INVALID';
	public const ERROR_NOBODY_TO_TELL = 'MAIL_COMPOSITION_STALL_NOBODY_TO_TELL';
	public const ERROR_NOT_DELIVERED = 'MAIL_COMPOSITION_STALL_NOT_DELIVERED';
	public const UNDELIVERED_DATA_KEY = 'undelivered';

	public function accept(string $reason): Result
	{
		if (!in_array($reason, self::REASONS, true))
		{
			return (new Result())->addError(new Error(
				'A valid reason for the stalled composition read is expected',
				self::ERROR_CONTRACT_INVALID,
			));
		}

		if (!Loader::includeModule('im') || !Loader::includeModule('intranet'))
		{
			return $this->nobodyToTell();
		}
		$administrators = (new SuitableAdministrators())->ids();
		if ($administrators === [])
		{
			return $this->nobodyToTell();
		}

		// A failed withdrawal leaves former recipients alone; im replaces the notice for everyone told again.
		$this->withdraw();
		$undelivered = 0;
		foreach ($administrators as $administrator)
		{
			try
			{
				if (!Notification::notifyAdminAboutStalledCompositionRead($administrator, $reason))
				{
					$undelivered++;
				}
			}
			catch (Throwable)
			{
				$undelivered++;
			}
		}

		if ($undelivered === count($administrators))
		{
			return (new Result())->addError(new Error(
				'The stalled composition read notice has reached no suitable administrator',
				self::ERROR_NOT_DELIVERED,
			));
		}

		return (new Result())->setData([self::UNDELIVERED_DATA_KEY => $undelivered]);
	}

	public function withdraw(): Result
	{
		try
		{
			if (!Loader::includeModule('im') || Notification::withdrawStalledCompositionReadNotice())
			{
				return new Result();
			}
		}
		catch (Throwable)
		{
		}

		return (new Result())->addError(new Error(
			'The stalled composition read notice could not be withdrawn',
			self::ERROR_NOT_DELIVERED,
		));
	}

	private function nobodyToTell(): Result
	{
		return (new Result())->addError(new Error(
			'The portal has no suitable administrator to tell of the stalled composition read',
			self::ERROR_NOBODY_TO_TELL,
		));
	}
}
