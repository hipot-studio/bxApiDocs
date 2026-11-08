<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * Assigns to a nested shape of {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto} only the
 * fields the `select` asked of it.
 *
 * A dotted `select` such as `communications.type` narrows the shape the way a flat one narrows the activity:
 * a field left out is never assigned and so never reaches the answer.
 */
final class NestedFieldProjection
{
	/**
	 * @param array<string, mixed> $values every field of the shape, by its published name.
	 * @param string[]|null $fields the fields asked of the shape; null answers all of them.
	 */
	public static function assign(Dto $shape, array $values, ?array $fields): void
	{
		if ($fields !== null)
		{
			$values = array_intersect_key($values, array_fill_keys($fields, true));
		}

		foreach ($values as $field => $value)
		{
			$shape->{$field} = $value;
		}
	}
}
