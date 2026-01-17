<?php

namespace Bitrix\Crm\Category;

class EditorHelper
{
	protected $entityTypeId;

	public function __construct(int $entityTypeId)
	{
		$this->entityTypeId = $entityTypeId;
	}

	public function getEditorConfigId(?int $categoryId, string $sourceFormId, $useUpperCase = true): string
	{
		if ($categoryId <= 0 || is_null($categoryId))
		{
			return $sourceFormId;
		}

		$key = $useUpperCase ? 'C' : 'c';

		return "{$sourceFormId}_{$key}_{$categoryId}";
	}

	public function getCategoryId(string $editorEntityTypeId): ?int
	{
		$parts = explode('_', mb_strtoupper($editorEntityTypeId));
		if ($parts[0] === 'DYNAMIC')
		{
			$categoryData = end($parts);

			return !str_starts_with($categoryData, 'C') ? null : (int)substr($categoryData, 1);
		}

		return (int)end($parts);
	}
}
