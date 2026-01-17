<?php

namespace Bitrix\Bizproc\Activity\Trigger;

class TriggerParameters
{
	private array $parameters;

	public function __construct(array $parameters)
	{
		$this->parameters = $parameters;
	}

	public function get(string $key): mixed
	{
		return $this->parameters[$key] ?? null;
	}

	public function set(string $key, mixed $value): static
	{
		$this->parameters[$key] = $value;

		return $this;
	}
}
