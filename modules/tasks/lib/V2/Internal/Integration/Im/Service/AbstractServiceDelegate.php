<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Service;

use Bitrix\Main\Loader;

/**
 * @template T of object
 */
abstract class AbstractServiceDelegate
{
	/** @var T|null */
	protected ?object $delegate = null;

	public function __construct(...$arguments)
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$this->setDelegate($this->createDelegate(...$arguments));
	}

	public function __call(string $name, array $arguments): mixed
	{
		return $this->delegate?->$name(...$arguments) ?? null;
	}

	public function setDelegate(object $delegate): static
	{
		$this->delegate = $delegate;
		return $this;
	}

	#[\ReturnTypeWillChange]
	abstract protected function createDelegate(...$arguments): mixed;
}
