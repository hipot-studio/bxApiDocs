<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

/**
 * Sends a reference book several fields of one list share once instead of once per field.
 *
 * The web provider hands the same set of values to every field it belongs to, and rightly so: the stages
 * of a deal describe the stage of the deal, its previous stage and its two stages by history alike. On
 * the wire that is the same list four times - on a portal with several funnels the stages alone run into
 * kilobytes.
 *
 * A set of one field is left in that field: a pointer at it would cost more than it saves, and a
 * client that knows nothing of the index goes on reading `items` as it did.
 *
 * This is transport and nothing more. Above the parser of the client no descriptor carries a pointer,
 * so no control, no formatter and no test of the form knows this class exists.
 */
final class ItemSetIndex
{
	/**
	 * @param array<int, array> $fields normalized descriptors (CONTRACT-DTO-01)
	 * @return array{fields: array<int, array>, itemSets: array<int, array{id: string, items: array}>}
	 */
	public static function fold(array $fields): array
	{
		$shared = self::findSharedSets($fields);
		if ($shared === [])
		{
			return ['fields' => array_values($fields), 'itemSets' => []];
		}

		$folded = [];
		foreach ($fields as $field)
		{
			$key = self::keyOf($field);
			if ($key !== null && isset($shared[$key]))
			{
				unset($field['items']);
				$field['itemsRef'] = $shared[$key]['id'];
			}

			$folded[] = $field;
		}

		return [
			'fields' => $folded,
			'itemSets' => array_values($shared),
		];
	}

	/**
	 * Sets more than one field of the list holds, numbered in the order they are met: an answer that
	 * reads top to bottom keeps the diff of two answers of one list small.
	 *
	 * @param array<int, array> $fields
	 * @return array<string, array{id: string, items: array}> by the key of the set
	 */
	private static function findSharedSets(array $fields): array
	{
		$countByKey = [];
		foreach ($fields as $field)
		{
			$key = self::keyOf($field);
			if ($key !== null)
			{
				$countByKey[$key] = ($countByKey[$key] ?? 0) + 1;
			}
		}

		$shared = [];
		foreach ($fields as $field)
		{
			$key = self::keyOf($field);
			if ($key === null || ($countByKey[$key] ?? 0) < 2 || isset($shared[$key]))
			{
				continue;
			}

			$shared[$key] = ['id' => (string)count($shared), 'items' => $field['items']];
		}

		return $shared;
	}

	/**
	 * What makes two sets the same one. The order of the values is part of it: it is the order the
	 * provider of the field chose and the order the form shows them in, so the same values listed
	 * differently are two sets.
	 *
	 * Null for a field with nothing to share - one without values at all, or with an empty list (the
	 * external sources of a deal on a portal that has none).
	 */
	private static function keyOf(array $field): ?string
	{
		$items = ($field['items'] ?? null);

		return is_array($items) && $items !== [] ? md5(serialize($items)) : null;
	}
}
