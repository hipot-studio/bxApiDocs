<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Main\Config\Feature;
use Bitrix\Vibecodeconnector\Internal\Config\Feature\AppChat;

class AppChatAvailability
{
	public function isEnabled(): bool
	{
		return Feature::isEnabled(AppChat::class);
	}
}
