<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * The aggregation capabilities an external application declares, as the portal accepts them (DTO-02).
 *
 * The raw array arrives from an external application over HTTP, so it is untrusted input: unknown
 * keys are dropped, a value of an unexpected type is dropped as well, and nothing leaves this class
 * as an exception. A malformed declaration simply produces an object that normalizes to
 * `Capabilities::unsupported()`, and so does one whose list goes past the accepted bound.
 *
 * The keys `scopeId`, `capsFingerprint`, `rowSetSemantics` and `where.dateRange` are not accepted:
 * their values are determined by the portal, and a sent one is ignored as any other unknown key.
 *
 * Columns are the only values living in a foreign namespace: the application names them by source
 * codes, because the field names of the dataset are unknown to it. The `translate*Columns()` methods
 * turn them into dataset field names with the map the caller supplies.
 */
final class ExternalCapabilitiesDeclaration
{
	/**
	 * Items of one declared list the portal accepts, the dictionary of exact operators included.
	 *
	 * A real declaration holds units of items: functions, truncation units and operators are intersected
	 * with the lists of the portal, and columns are translated through the field map of the dataset, so
	 * items past this bound cannot add anything. The bound matters because the body size limit of the
	 * answer allows about 130 000 short items, and the raw declaration is parsed after every read of its
	 * cache entry.
	 *
	 * A list that goes past the bound is not truncated: the whole declaration is refused, so the channel
	 * stays on the row path. `excludedColumns` and `where.inexactColumns` are lists of exceptions, and a
	 * dropped exception widens what the portal accepts instead of narrowing it.
	 */
	private const MAX_LIST_ITEMS = 1000;

	/**
	 * @param int[] $protocolVersions
	 * @param string[] $functions
	 * @param string[] $dateTruncUnits
	 * @param string[] $excludedColumns Source codes.
	 * @param string[] $inexactColumns Source codes.
	 * @param array<string, string[]> $exactOperators
	 */
	private function __construct(
		public readonly array $protocolVersions,
		public readonly array $functions,
		public readonly bool $exactCountDistinct,
		public readonly array $dateTruncUnits,
		public readonly bool $having,
		public readonly bool $groupLimit,
		public readonly bool $orderBy,
		public readonly array $excludedColumns,
		public readonly bool $binaryStringOperations,
		public readonly bool $floatNanOrderingCompatible,
		public readonly array $inexactColumns,
		public readonly array $exactOperators,
	)
	{
	}

	/**
	 * The declaration of an application that declared nothing: it normalizes to `unsupported()`.
	 */
	public static function createEmpty(): self
	{
		return self::declaringNothing();
	}

	public static function fromArray(mixed $raw): self
	{
		$declaration = is_array($raw) ? $raw : [];
		$semantics = is_array($declaration['semantics'] ?? null) ? $declaration['semantics'] : [];
		$where = is_array($declaration['where'] ?? null) ? $declaration['where'] : [];

		$protocolVersions = self::intList($declaration['protocolVersions'] ?? null);
		$functions = self::stringList($declaration['functions'] ?? null);
		$dateTruncUnits = self::stringList($declaration['dateTruncUnits'] ?? null);
		$excludedColumns = self::stringList($declaration['excludedColumns'] ?? null);
		$inexactColumns = self::stringList($where['inexactColumns'] ?? null);
		$exactOperators = self::operatorMap($where['exactOperators'] ?? null);

		if (
			$protocolVersions === null
			|| $functions === null
			|| $dateTruncUnits === null
			|| $excludedColumns === null
			|| $inexactColumns === null
			|| $exactOperators === null
		)
		{
			return self::declaringNothing();
		}

		return new self(
			protocolVersions: $protocolVersions,
			functions: $functions,
			exactCountDistinct: self::flag($declaration['exactCountDistinct'] ?? null),
			dateTruncUnits: $dateTruncUnits,
			having: self::flag($declaration['having'] ?? null),
			groupLimit: self::flag($declaration['groupLimit'] ?? null),
			orderBy: self::flag($declaration['orderBy'] ?? null),
			excludedColumns: $excludedColumns,
			binaryStringOperations: self::flag($semantics['binaryStringOperations'] ?? null),
			floatNanOrderingCompatible: self::flag($semantics['floatNanOrderingCompatible'] ?? null),
			inexactColumns: $inexactColumns,
			exactOperators: $exactOperators,
		);
	}

	private static function declaringNothing(): self
	{
		return new self(
			protocolVersions: [],
			functions: [],
			exactCountDistinct: false,
			dateTruncUnits: [],
			having: false,
			groupLimit: false,
			orderBy: false,
			excludedColumns: [],
			binaryStringOperations: false,
			floatNanOrderingCompatible: false,
			inexactColumns: [],
			exactOperators: [],
		);
	}

	/**
	 * @param array<string, string> $mapFields EXTERNAL_CODE => NAME.
	 *
	 * @return string[] Dataset field names.
	 */
	public function translateExcludedColumns(array $mapFields): array
	{
		return self::translate($this->excludedColumns, $mapFields);
	}

	/**
	 * @param array<string, string> $mapFields EXTERNAL_CODE => NAME.
	 *
	 * @return string[] Dataset field names.
	 */
	public function translateInexactColumns(array $mapFields): array
	{
		return self::translate($this->inexactColumns, $mapFields);
	}

	/**
	 * A code the map does not know is dropped silently: a declaration about a column the dataset does
	 * not have means nothing.
	 *
	 * @param string[] $codes
	 * @param array<string, string> $mapFields
	 *
	 * @return string[]
	 */
	private static function translate(array $codes, array $mapFields): array
	{
		$names = [];
		foreach ($codes as $code)
		{
			$name = $mapFields[$code] ?? null;
			if (!is_string($name) || $name === '' || in_array($name, $names, true))
			{
				continue;
			}

			$names[] = $name;
		}

		return $names;
	}

	/**
	 * Only a declared `true` is a claim: a missing key, `null`, `0` or the string `'Y'` claim nothing.
	 */
	private static function flag(mixed $value): bool
	{
		return $value === true;
	}

	/**
	 * @return int[]|null Null when the list goes past `MAX_LIST_ITEMS`.
	 */
	private static function intList(mixed $value): ?array
	{
		if (!is_array($value))
		{
			return [];
		}

		$items = [];
		$seen = [];
		foreach ($value as $item)
		{
			if (!is_int($item) || isset($seen[$item]))
			{
				continue;
			}

			if (count($items) >= self::MAX_LIST_ITEMS)
			{
				return null;
			}

			$seen[$item] = true;
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * @return string[]|null Null when the list goes past `MAX_LIST_ITEMS`.
	 */
	private static function stringList(mixed $value): ?array
	{
		if (!is_array($value))
		{
			return [];
		}

		$items = [];
		// Dedupe over keys: a string list holds only strings, so the numeric-string coercion of an array
		// key cannot merge two distinct items.
		$seen = [];
		foreach ($value as $item)
		{
			if (!is_string($item) || $item === '' || isset($seen[$item]))
			{
				continue;
			}

			if (count($items) >= self::MAX_LIST_ITEMS)
			{
				return null;
			}

			$seen[$item] = true;
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * @return array<string, string[]>|null Null when the dictionary or a type list goes past `MAX_LIST_ITEMS`.
	 */
	private static function operatorMap(mixed $value): ?array
	{
		if (!is_array($value))
		{
			return [];
		}

		$operators = [];
		foreach ($value as $operator => $types)
		{
			if (!is_string($operator) || $operator === '')
			{
				continue;
			}

			if (count($operators) >= self::MAX_LIST_ITEMS)
			{
				return null;
			}

			$operatorTypes = self::stringList($types);
			if ($operatorTypes === null)
			{
				return null;
			}

			$operators[$operator] = $operatorTypes;
		}

		return $operators;
	}
}
