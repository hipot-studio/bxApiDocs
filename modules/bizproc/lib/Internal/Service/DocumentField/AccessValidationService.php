<?php

namespace Bitrix\Bizproc\Internal\Service\DocumentField;

use Bitrix\Bizproc\BaseType\Base;
use Bitrix\Bizproc\Internal\Entity\DocumentField\UserAccess;
use Bitrix\Bizproc\Result;

class AccessValidationService
{
	public function isUserHasAccessToValue(mixed $type, int $userId, mixed $value): Result
	{
		$object = is_string($type) && class_exists($type) && is_subclass_of($type, Base::class)
			? new $type()
			: $type
		;

		return $object instanceof UserAccess ? $object->isUserHasAccess($userId, $value) : new Result();
	}
}