<?php

namespace Bitrix\Rest\V3\Dto\Validation;

use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Rest\V3\Dto\DtoField;

trait EditableGroupValidationTrait
{
	protected function isEditable(DtoField $field, ValidationGroup $group): bool
	{
		if ($field->getEditableGroups() === null)
		{
			return false;
		}

		if (empty($field->getEditableGroups()))
		{
			return true;
		}

		foreach ($field->getEditableGroups() as $editableGroup)
		{
			if ($group->isEquals(ValidationGroup::create($editableGroup)))
			{
				return true;
			}
		}

		return false;
	}
}
