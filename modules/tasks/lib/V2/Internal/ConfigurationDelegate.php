<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal;

use Bitrix\Main\Config\Configuration;

/**
 * @method mixed get(string $name) Get the value from the configuration.
 *
 * @see Bitrix\Main\Config\Configuration
 */
class ConfigurationDelegate
{
	public Configuration $delegate;

	public function __construct()
	{
		$this->delegate = Configuration::getInstance('tasks');
	}

	public function __call($name, $arguments): mixed
	{
		return $this->delegate->$name(...$arguments);
	}
}
