<?php

namespace Bitrix\Crm\EntityForm;

use Bitrix\Crm\Category\EditorHelper;
use Bitrix\Crm\Service\Factory;

trait EntityTypeIdResolveTrait
{
	public function getCrmEntityTypeIdByEntityTypeId(string $editorEntityTypeId): int
	{
		// Resolve the CRM entity type ID using the current entity type name.
		$entityTypeId = \CCrmOwnerType::ResolveID($editorEntityTypeId);
		// Check if the resolved CRM entity type ID is defined.
		if (\CCrmOwnerType::IsDefined($entityTypeId))
		{
			return $entityTypeId;
		}

		$firstUnderscoreIndex = strpos($editorEntityTypeId, '_');
		// Loop while there is an underscore in the entity type ID string.
		while ($firstUnderscoreIndex)
		{
			// Find the last underscore in the entity type ID string.
			$lastUnderscoreIndex = strrpos($editorEntityTypeId, '_');
			$entityTypeName = $editorEntityTypeId;
			// Loop while there is an underscore in the entity type name.
			while ($lastUnderscoreIndex)
			{
				$entityTypeId = \CCrmOwnerType::ResolveID($entityTypeName);
				if (\CCrmOwnerType::IsDefined($entityTypeId))
				{
					return $entityTypeId;
				}
				// Update the entity type name by removing the last underscore and the following characters.
				$entityTypeName = substr($editorEntityTypeId, 0, $lastUnderscoreIndex);
				$lastUnderscoreIndex = strrpos($entityTypeName, '_');
			}

			$entityTypeId = \CCrmOwnerType::ResolveID($entityTypeName);
			if (\CCrmOwnerType::IsDefined($entityTypeId))
			{
				return $entityTypeId;
			}
			// Update the entity type ID by removing the first underscore and the preceding characters.
			$editorEntityTypeId = substr($editorEntityTypeId, $firstUnderscoreIndex+1);
			$firstUnderscoreIndex = strpos($editorEntityTypeId, '_');
		}

		return $entityTypeId;
	}

	public function getCategoryId(string $editorEntityTypeId): ?int
	{
		$entityTypeId = $this->getCrmEntityTypeIdByEntityTypeId($editorEntityTypeId);

		if (!$this->factory || !$this->factory->isCategoriesEnabled())
		{
			return null;
		}

		return (new EditorHelper($entityTypeId))->getCategoryId($editorEntityTypeId);
	}

	public function getFilterName(Factory $factory): string
	{
		return 'entity_editor_config_' . mb_strtolower($factory->getEntityName());
	}
}
