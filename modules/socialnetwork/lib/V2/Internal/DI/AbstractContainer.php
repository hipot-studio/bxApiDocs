<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\DI;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\NotImplementedException;

abstract class AbstractContainer
{
	private static array $instances = [];

	private ServiceLocator $locator;

	public static function getInstance(): static
	{
		if (!isset(self::$instances[static::class]))
		{
			self::$instances[static::class] = new static();
		}

		return self::$instances[static::class];
	}

	private function __construct()
	{
		$this->init();
	}

	/**
	 * @template T of object
	 * @param class-string<T>|string $idOrClass
	 * @return T
	 */
	public function get(string $idOrClass): object
	{
		return $this->locator->get($idOrClass);
	}

	protected function init(): void
	{
		$this->locator = ServiceLocator::getInstance();
	}

	/**
	 * @throws NotImplementedException
	 */
	public function __serialize(): array
	{
		throw new NotImplementedException('Can not serialize singleton');
	}

	/**
	 * @throws NotImplementedException
	 */
	private function __clone()
	{
		throw new NotImplementedException('Can not clone singleton');
	}
}
