<?php

namespace Bitrix\Bizproc\Starter\Constraint;

use Bitrix\Main\Error;

interface ConstraintInterface
{
	public function isSatisfied(): bool;

	public function getError(): ?Error;
}
