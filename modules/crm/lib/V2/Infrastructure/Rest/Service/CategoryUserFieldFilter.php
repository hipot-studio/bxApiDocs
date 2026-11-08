<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Service;

use Bitrix\Crm\Category\ItemCategoryUserField;
use Bitrix\Crm\V2\Public\EntityType;

final class CategoryUserFieldFilter
{
	/**
	 * @param array<int|string, mixed> $fields
	 * @return array<int|string, mixed>
	 */
	public function filter(EntityType $entityType, int $categoryId, array $fields): array
	{
		if ($fields === [])
		{
			return [];
		}

		$fieldsByName = [];
		foreach ($fields as $key => $field)
		{
			$name = is_array($field) ? ($field['FIELD_NAME'] ?? $key) : $key;
			$fieldsByName[$name] = true;
		}

		$allowedFields = (new ItemCategoryUserField($entityType->getId()))->filter($categoryId, $fieldsByName);
		if ($allowedFields === $fieldsByName)
		{
			return $fields;
		}

		return array_filter(
			$fields,
			static function ($field, $key) use ($allowedFields): bool {
				$name = is_array($field) ? ($field['FIELD_NAME'] ?? $key) : $key;

				return isset($allowedFields[$name]);
			},
			ARRAY_FILTER_USE_BOTH,
		);
	}
}
