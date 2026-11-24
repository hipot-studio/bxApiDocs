<?php

namespace Bitrix\AI\Payload\Formatter;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Facade\User;

abstract class Formatter
{
	/**
	 * Expects text for replacement.
	 */
	public function __construct(
		protected string $text,
		protected IEngine $engine,
	){}

	/**
	 * Retrieves user data by ID and stores it to the static cache keyed by user ID.
	 *
	 * @return array
	 */
	protected function getUserDataById(int $userId): array
	{
		static $users = [];

		if (!isset($users[$userId]))
		{
			$users[$userId] = User::getUserDataById($userId);
		}

		return $users[$userId];
	}
}
