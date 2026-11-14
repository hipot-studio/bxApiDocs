<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

final class CursorPayload
{
	public function __construct(private readonly array $values)
	{
	}

	public function toArray(): array
	{
		return $this->values;
	}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->values[$key] ?? $default;
	}

	public function kind(): string
	{
		return (string)$this->values['kind'];
	}
}
