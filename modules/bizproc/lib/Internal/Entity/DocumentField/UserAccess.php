<?php

namespace Bitrix\Bizproc\Internal\Entity\DocumentField;

use Bitrix\Bizproc\Result;

interface UserAccess
{
	public function isUserHasAccess(int $userId, mixed $value): Result;
}