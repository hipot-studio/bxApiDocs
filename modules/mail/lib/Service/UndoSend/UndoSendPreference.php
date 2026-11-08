<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\UndoSend;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

class UndoSendPreference
{
	public const ERROR_INVALID_DELAY = 'invalid_delay';
	public const DEFAULT_DELAY_SECONDS = 5;
	public const ALLOWED_DELAY_SECONDS = [0, 5, 10, 20];

	private const OPTION_CATEGORY = 'mail';
	private const OPTION_NAME = 'undo_send_delay';

	public function get(int $userId): int
	{
		$value = (int)\CUserOptions::GetOption(
			self::OPTION_CATEGORY,
			self::OPTION_NAME,
			self::DEFAULT_DELAY_SECONDS,
			$userId,
		);

		return in_array($value, self::ALLOWED_DELAY_SECONDS, true)
			? $value
			: self::DEFAULT_DELAY_SECONDS
		;
	}

	public function set(int $userId, int $delaySeconds): Result
	{
		$result = new Result();
		if (!in_array($delaySeconds, self::ALLOWED_DELAY_SECONDS, true))
		{
			return $result->addError(new Error('Undo send delay is invalid.', self::ERROR_INVALID_DELAY));
		}

		\CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			self::OPTION_NAME,
			$delaySeconds,
			false,
			$userId,
		);

		return $result->setData(['delaySeconds' => $delaySeconds]);
	}
}
