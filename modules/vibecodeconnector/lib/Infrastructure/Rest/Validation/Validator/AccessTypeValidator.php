<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Validation\Validator;

use Bitrix\Main\Validation\ValidationResult;
use Bitrix\Main\Validation\Validator\InArrayValidator;
use Bitrix\Main\Validation\Validator\ValidatorInterface;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;

final class AccessTypeValidator implements ValidatorInterface
{
	public function validate(mixed $value): ValidationResult
	{
		return (new InArrayValidator(CatalogItemAccessType::values(), true))->validate($value);
	}
}
