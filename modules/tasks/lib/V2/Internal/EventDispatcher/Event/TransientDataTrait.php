<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\Event;

trait TransientDataTrait
{
	private array $data = [];

	private function hasTransient(string $name): bool
	{
		return array_key_exists($name, $this->data);
	}

	private function setTransient(string $name, mixed $value): void
	{
		$this->data[$name] = $value;
	}

	private function getTransient(string $name): mixed
	{
		return $this->data[$name] ?? null;
	}

	private function unsetTransient(string $name): void
	{
		unset($this->data[$name]);
	}

	public function __get(string $name): mixed
	{
		return $this->getTransient($name);
	}
	
	public function __set(string $name, mixed $value): void
	{
		$this->setTransient($name, $value);
	}

	public function __isset(string $name): bool
	{
		return $this->hasTransient($name);
	}

	public function __unset(string $name): void
	{
		$this->unsetTransient($name);
	}

	public function offsetExists(mixed $offset): bool
	{
		return $this->hasTransient($offset);
	}

	public function offsetGet(mixed $offset): mixed
	{
		return $this->getTransient($offset);
	}
	public function offsetSet(mixed $offset, mixed $value): void
	{
		$this->setTransient($offset, $value);
	}
	public function offsetUnset(mixed $offset): void
	{
		$this->unsetTransient($offset);
	}
}
