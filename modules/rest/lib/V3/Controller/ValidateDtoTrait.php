<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoValidatorHelper;

trait ValidateDtoTrait
{
	protected function validateDto(Dto $dto, string $group = 'default'): bool
	{
		$result = DtoValidatorHelper::validate($dto, ValidationGroup::create($group));

		if ($result->isSuccess())
		{
			return true;
		}
		else
		{
			$this->addErrors($result->getErrors());

			return false;
		}
	}
}
