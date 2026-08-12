<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Validator;

use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\InArrayValidator;
use Bitrix\Main\Validation\Validator\ValidatorInterface;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;

final class ItemTypeValidator implements ValidatorInterface
{
	public function validate(mixed $value): ValidationResult
	{
		return (new InArrayValidator(CatalogItemType::values(), true))->validate($value);
	}
}
