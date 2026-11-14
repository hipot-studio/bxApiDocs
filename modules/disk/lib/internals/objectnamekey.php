<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals;

use Bitrix\Main\Application;
use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * Two object names are the same name in a folder when their keys are equal:
 * the case and invisible characters do not matter, diacritics do.
 */
final class ObjectNameKey
{
	private const INVISIBLE_CHARACTERS = '/[\p{Cf}\x{034F}\x{180B}-\x{180D}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u';

	public static function of(string $name): string
	{
		$visible = self::removeInvisibleCharacters($name);

		return hash('sha256', self::nfc(mb_convert_case(self::nfc($visible), MB_CASE_FOLD_SIMPLE, 'UTF-8')));
	}

	/**
	 * Finds the object with the same name among the objects matched by the ORM parameters.
	 * An exact name wins over a case variant.
	 */
	public static function findSameName(array $parameters, string $name): ?array
	{
		$rowsWithKey = ObjectTable::getList(self::buildParametersWithKey($parameters, $name))->fetchAll();
		foreach ($rowsWithKey as $row)
		{
			if ($row['NAME'] === $name)
			{
				return $row;
			}
		}

		$rowsWithoutKey = ObjectTable::getList(self::buildParametersWithoutKey($parameters, [$name]))->fetchAll();

		return self::pickSameName([...$rowsWithKey, ...$rowsWithoutKey], $name);
	}

	/**
	 * Finds up to $limit objects with the same name among the objects matched by the ORM parameters.
	 * The parameters must select NAME.
	 */
	public static function findAllSameName(array $parameters, string $name, ?int $limit = null): array
	{
		$parametersWithKey = self::buildParametersWithKey($parameters, $name);
		if ($limit !== null)
		{
			$parametersWithKey['limit'] = $limit;
		}

		$rows = ObjectTable::getList($parametersWithKey)->fetchAll();
		$key = self::of($name);
		$rowsWithoutKey = ObjectTable::getList(self::buildParametersWithoutKey($parameters, [$name]));
		while (($limit === null || count($rows) < $limit) && ($row = $rowsWithoutKey->fetch()))
		{
			if (self::of((string)$row['NAME']) === $key)
			{
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Without a folder in the filter the key has no index to go by, so the index on NAME narrows the rows first:
	 * the collation of NAME that ignores the case and diacritics lets through every name with the same key.
	 */
	public static function buildParametersWithKey(array $parameters, string $name): array
	{
		$filter = [...($parameters['filter'] ?? []), ...self::buildKeyFilter($name)];
		if (!self::isBoundToParent($filter))
		{
			$filter['=NAME'] = $name;
		}

		return [...$parameters, 'filter' => $filter];
	}

	public static function buildKeyFilter(string $name): array
	{
		return ['=NAME_KEY' => self::of($name)];
	}

	/**
	 * Candidates among the rows stored before NAME_KEY appeared; must be narrowed down by self::pickSameName().
	 *
	 * A case sensitive collation of NAME (PostgreSQL) would miss the case variants of a name, so inside
	 * folders the names are compared in lower case among the rows of those folders.
	 *
	 * @param string[] $names
	 */
	public static function buildParametersWithoutKey(array $parameters, array $names, ?bool $caseSensitiveCollation = null): array
	{
		$caseSensitiveCollation ??= Application::getConnection() instanceof PgsqlConnection;
		$filter = [...($parameters['filter'] ?? []), '=NAME_KEY' => null];
		if (!$caseSensitiveCollation || !self::isBoundToParent($filter))
		{
			$filter['@NAME'] = array_values($names);

			return [...$parameters, 'filter' => $filter];
		}

		$filter['@NAME_LOWER'] = new SqlExpression(
			implode(', ', array_fill(0, count($names), 'LOWER(?s)')),
			...array_values($names),
		);

		return [
			...$parameters,
			'runtime' => [
				...($parameters['runtime'] ?? []),
				new ExpressionField('NAME_LOWER', 'LOWER(%s)', 'NAME'),
			],
			'filter' => $filter,
		];
	}

	/**
	 * @param iterable<array{NAME: string}> $rows
	 */
	public static function pickSameName(iterable $rows, string $name): ?array
	{
		$key = self::of($name);
		$picked = null;
		foreach ($rows as $row)
		{
			if (self::of((string)$row['NAME']) !== $key)
			{
				continue;
			}

			if ($row['NAME'] === $name)
			{
				return $row;
			}

			$picked ??= $row;
		}

		return $picked;
	}

	private static function isBoundToParent(array $filter): bool
	{
		foreach (['PARENT_ID', '=PARENT_ID', '@PARENT_ID'] as $parentKey)
		{
			if (!empty($filter[$parentKey]))
			{
				return true;
			}
		}

		return false;
	}

	private static function removeInvisibleCharacters(string $name): string
	{
		$visible = preg_replace(self::INVISIBLE_CHARACTERS, '', $name);

		// A name of invisible characters only keeps them, otherwise all such names would share one key.
		return is_string($visible) && $visible !== '' ? $visible : $name;
	}

	private static function nfc(string $value): string
	{
		if (!class_exists(\Normalizer::class))
		{
			return $value;
		}

		$normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);

		return is_string($normalized) ? $normalized : $value;
	}
}
