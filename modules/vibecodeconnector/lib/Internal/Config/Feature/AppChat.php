<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

final class AppChat extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
