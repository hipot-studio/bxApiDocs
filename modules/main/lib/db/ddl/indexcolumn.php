<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl;

class IndexColumn
{
	public function __construct(
		public readonly string $name,
		public readonly ?int $length = null,
		public readonly ?string $sort = null,
	)
	{
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @param array<string|self> $columns
	 * @return self[]
	 */
	public static function normalizeList(array $columns): array
	{
		return array_map(static fn(string|self $col): self => self::fromMixed($col), $columns);
	}

	public static function fromMixed(string|self $column): self
	{
		if ($column instanceof self)
		{
			return $column;
		}
		return self::parse($column);
	}

	/**
	 * Parses a string like "COLUMN_NAME(155) DESC" into IndexColumn.
	 */
	private static function parse(string $column): self
	{
		$name = $column;
		$length = null;
		$sort = null;

		if (preg_match('/^(\w+)(?:\((\d+)\))?\s*(ASC|DESC)?$/i', trim($column), $matches))
		{
			$name = $matches[1];
			$length = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : null;
			$sort = isset($matches[3]) && $matches[3] !== '' ? strtoupper($matches[3]) : null;
		}

		return new self($name, $length, $sort);
	}
}
