<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Service;

use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppsFolderAvailability;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatAvailability;

/**
 * Whether the catalog button carries the counter of the user's new apps — in the web messenger
 * and in the mobile one alike. The counter gives way to the app chats: once every new app comes
 * with a chat and the messenger collects the chats in its folder, the folder announces the
 * novelties itself, so a badge on the button would only double it. With either half off the
 * badge is back.
 */
class NewAppsBadgeAvailability
{
	public function __construct(
		private readonly AppChatAvailability $appChatAvailability = new AppChatAvailability(),
		private readonly AppsFolderAvailability $appsFolderAvailability = new AppsFolderAvailability(),
	) {
	}

	public function isAvailable(): bool
	{
		return !($this->appChatAvailability->isEnabled() && $this->appsFolderAvailability->isEnabled());
	}
}
