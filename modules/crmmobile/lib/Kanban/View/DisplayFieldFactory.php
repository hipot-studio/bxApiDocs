<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Kanban\View;

use Bitrix\Crm\Field\Collection;
use Bitrix\Crm\Service\Display\Field;
use Bitrix\CrmMobile\Kanban\ControllerStrategy\FieldNotFoundException;

/**
 * Makes a display field out of the entity field declaration.
 */
final class DisplayFieldFactory
{
	/**
	 * @throws FieldNotFoundException
	 */
	public static function create(string $fieldName, Collection $fieldsCollection): Field
	{
		$field = $fieldsCollection->getField($fieldName);
		if (!$field)
		{
			throw new FieldNotFoundException('Field: ' . $fieldName . ' not found');
		}

		$fieldId = $field->getName();
		if (!empty($field->getUserField()))
		{
			return Field::createFromUserField($fieldId, $field->getUserField());
		}

		$displayField = (Field::createByType($field->getType(), $fieldId))->setTitle($field->getTitle());

		foreach ($field->getSettings() as $name => $setting)
		{
			$displayField->addDisplayParam($name, $setting);
		}

		if ($entityType = $field->getCrmStatusType())
		{
			$displayField->addDisplayParam('ENTITY_TYPE', $entityType);
		}

		if ($valueType = $field->getValueType())
		{
			$displayField->addDisplayParam('VALUE_TYPE', $valueType);
		}

		return $displayField;
	}
}
