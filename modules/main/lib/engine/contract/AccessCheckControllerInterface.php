<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\Contract;

use Bitrix\Main\Access\AccessibleController;

interface AccessCheckControllerInterface
{
	public function getAccessController(): AccessibleController;
}
