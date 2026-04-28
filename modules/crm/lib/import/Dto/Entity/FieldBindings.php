<?php

namespace Bitrix\Crm\Import\Dto\Entity;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings\Binding;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class FieldBindings implements Arrayable, JsonSerializable
{
	/**
	 * @var Binding[]
	 */
	private array $bindings = [];

	public function __construct(
		/** @var Binding[] $bindings */
		array $bindings = [],
	)
	{
		array_map($this->set(...), $bindings);
	}

	public function set(Binding $binding): self
	{
		$this->bindings[$binding->getFieldId()] = $binding;

		return $this;
	}

	public function get(string $fieldId): ?Binding
	{
		return $this->bindings[$fieldId] ?? null;
	}

	public function getColumnIndexByFieldId(string $fieldId): ?int
	{
		return $this->get($fieldId)?->getColumnIndex();
	}

	public static function tryFromArray(array $rawFieldBindings): ?self
	{
		$rawBindings = $rawFieldBindings['bindings'] ?? null;
		if (!is_array($rawBindings))
		{
			return null;
		}

		$instance = new self();

		foreach ($rawBindings as $rawBinding)
		{
			$binding = Binding::tryFromArray($rawBinding);
			if ($binding !== null)
			{
				$instance->set($binding);
			}
		}

		return $instance;
	}

	public function toArray(): array
	{
		return [
			'bindings' => array_values(
				array_map(static fn (Binding $binding) => $binding->toArray(), $this->bindings)
			),
		];
	}

	public function getFieldIdColumnIndexMap(): array
	{
		$bindings = [];
		foreach ($this->bindings as $binding)
		{
			$bindings[$binding->getFieldId()] = $binding->getColumnIndex();
		}

		return $bindings;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
