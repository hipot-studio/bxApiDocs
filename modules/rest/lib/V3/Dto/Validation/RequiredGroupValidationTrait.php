<?php

namespace Bitrix\Rest\V3\Dto\Validation;

use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Rest\V3\Dto\DtoField;

trait RequiredGroupValidationTrait
{
	protected function isRequired(DtoField $field, ValidationGroup $group): bool
	{
		if ($field->getRequiredGroups() === null)
		{
			return false;
		}

		if (empty($field->getRequiredGroups()))
		{
			return true;
		}

		foreach ($field->getRequiredGroups() as $requiredGroup)
		{
			if ($group->isEquals(ValidationGroup::create($requiredGroup)))
			{
				return true;
			}
		}

		return false;
	}
}
