<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common\Collection;

trait IteratorAggregateTrait
{
	abstract protected function &getArray(): array;

	public function getIterator(): \Traversable
	{
		yield from $this->getArray();
	}

	/**
	 * @param callable(mixed $value, int|string $key): bool $predicate
	 */
	public function filter(callable $predicate): self
	{
		$filtered = clone $this;
		$internalArray = &$filtered->getArray();
		$keyToUnset = [];
		foreach ($internalArray as $key => $value)
		{
			if (!$predicate($value, $key))
			{
				$keyToUnset[] = $key;
			}
		}

		foreach ($keyToUnset as $key)
		{
			unset($internalArray[$key]);
		}

		return $filtered;
	}
}
