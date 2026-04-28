<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Common\Collection;

trait ArrayAccessTrait
{
	abstract protected function &getArray(): array;

	public function offsetExists(mixed $offset): bool
	{
		return isset($this->getArray()[$offset]);
	}

	public function offsetGet(mixed $offset): mixed
	{
		return $this->getArray()[$offset] ?? null;
	}

	public function offsetSet(mixed $offset, mixed $value): void
	{
		$this->getArray()[$offset] = $value;
	}

	public function offsetUnset(mixed $offset): void
	{
		unset($this->getArray()[$offset]);
	}
}
