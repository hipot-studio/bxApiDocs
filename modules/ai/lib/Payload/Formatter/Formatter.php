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
	 * Retrieves user data and stores it to the static cache.
	 *
	 * @return array
	 */
	protected function getCurrentUserData(): array
	{
		static $data = [];

		$engineId = spl_object_id($this->engine);
		if (!array_key_exists($engineId, $data))
		{
			$data[$engineId] = User::getCurrentUserData();
		}

		return $data[$engineId];
	}
}
