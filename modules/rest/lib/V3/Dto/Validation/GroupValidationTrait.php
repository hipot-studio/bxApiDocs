<?php

namespace Bitrix\Rest\V3\Dto\Validation;

use Bitrix\Rest\V3\Dto\DtoField;

trait GroupValidationTrait
{
	public function isRequired(DtoField $field, string $group): bool
	{
		if ($field->getRequiredGroups() === null)
		{
			return false;
		}

		if (empty($field->getRequiredGroups()))
		{
			return true;
		}

		return in_array($group, $field->getRequiredGroups(), true);
	}
}
