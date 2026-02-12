<?php

namespace Bitrix\Im\V2;

interface AccessCheckable
{
	public function checkAccess(?int $userId = null): Result;
}