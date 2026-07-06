<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity;

use Bitrix\Main;

/**
 * @method int|string|null getId()
 * @method mixed getLogin()
 * @method mixed getEmail()
 * @method mixed getFullName()
 * @method mixed getFirstName()
 * @method mixed getLastName()
 * @method mixed getSecondName()
 * @method array getUserGroups()
 * @method string getFormattedName()
 * @method bool canDoOperation(string $operationName)
 * @method bool isAdmin()
 */
class CurrentUser
{
	private static ?self $instance = null;

	private function __construct(private Main\Engine\CurrentUser $currentUser)
	{

	}

	public static function getInstance(): self
	{
		return self::$instance ??= new self(Main\Engine\CurrentUser::get());
	}

	public function __call($name, $arguments): mixed
	{
		return $this->currentUser->$name(...$arguments);
	}

	public function __wakeup(): void
	{
		throw new \RuntimeException('Cannot unserialize singleton instance');
	}

	private function __clone(): void
	{
		throw new \RuntimeException('Cannot clone singleton instance');
	}
}
