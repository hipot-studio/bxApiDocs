<?php

namespace Bitrix\Crm\Import\Collection;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Serializer\ImportEntityFieldSerializer;
use Bitrix\Main\DI\ServiceLocator;

final class FieldCollection
{
	private array $fields = [];

	/**
	 * @param ImportEntityFieldInterface[] $fields
	 */
	public function __construct(array $fields = [])
	{
		$this->pushList($fields);
	}

	public function push(ImportEntityFieldInterface $field): self
	{
		$this->fields[] = $field;

		return $this;
	}

	/**
	 * @param ImportEntityFieldInterface[] $fields
	 * @return self
	 */
	public function pushList(array $fields): self
	{
		array_map($this->push(...), $fields);

		return $this;
	}

	public function merge(self $collection): self
	{
		$this->pushList($collection->getAll());

		return $this;
	}

	/**
	 * @return ImportEntityFieldInterface[]
	 */
	public function getAll(): array
	{
		return $this->fields;
	}

	public function getIds(): array
	{
		return array_map(static fn (ImportEntityFieldInterface $field) => $field->getId(), $this->fields);
	}

	public function first(?callable $filter = null): ?ImportEntityFieldInterface
	{
		if ($filter !== null && is_callable($filter))
		{
			foreach ($this->fields as $field)
			{
				if ($filter($field))
				{
					return $field;
				}
			}

			return null;
		}

		return $this->fields[0] ?? null;
	}

	public function filter(callable $filter): self
	{
		$fields = [];
		foreach ($this->fields as $field)
		{
			if ($filter($field))
			{
				$fields[] = $field;
			}
		}

		return new self($fields);
	}

	public function map(callable $preparer): array
	{
		$items = [];
		foreach ($this->fields as $field)
		{
			$items[] = $preparer($field);
		}

		return $items;
	}

	public function toArray(): array
	{
		return ServiceLocator::getInstance()
			->get(ImportEntityFieldSerializer::class)
			?->serializeList($this->fields) ?? [];
	}
}
