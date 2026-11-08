<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Whether app chats also belong to the default recent section. When disabled they are reachable
 * through the vibecodeApp folder only.
 */
final class AppChatDefaultRecent extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
