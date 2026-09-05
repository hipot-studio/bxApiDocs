<?php

namespace Bitrix\Main\Config\Feature;

use Bitrix\Main\Engine\CurrentUser;

/**
 * Context for checking feature flags.
 */
class Context
{
	private static ?Context $current = null;

	public readonly ?int $userId;

	public readonly array $params;

	public function __construct(?int $userId = null, array $params = [])
	{
		// We assume that userId < 1 is an anonymous and force it to null.
		$this->userId = ($userId !== null && $userId < 1) ? null : $userId;
		$this->params = $params;
	}

	/**
	 * Returns the context for the current user.
	 *
	 * @return Context
	 */
	public static function getCurrent(): Context
	{
		if (static::$current === null)
		{
			$userId = (int)CurrentUser::get()->getId();

			static::$current = new static($userId);
		}

		return static::$current;
	}
}
