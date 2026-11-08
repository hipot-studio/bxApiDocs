<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant;

use Bitrix\AiAssistant\Config\Feature;
use Bitrix\AiAssistant\Facade\PortalDetails;
use Bitrix\AiAssistant\McpToken\Service\McpEntryPointAccess;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

class RemoteMcp
{
	public function isLeftMenuItemAvailable(): bool
	{
		if (!Loader::includeModule('aiassistant'))
		{
			return false;
		}

		if ($this->isBox())
		{
			// The gate class ships with aiassistant, and a box may take this module first: while the
			// gate is absent the item is not offered, which is how a box behaved before the delivery.
			if (!class_exists(McpEntryPointAccess::class))
			{
				return false;
			}

			return $this->isBoxMcpServerEnabled()
				&& $this->canUseLocalTokens((int)CurrentUser::get()->getId());
		}

		return Loader::includeModule('bitrix24')
			&& Feature::getInstance()->isMCPEnabled()
			&& Option::get('aiassistant', 'left_menu_mcp', 'N') === 'Y';
	}

	protected function isBox(): bool
	{
		return PortalDetails::isBox();
	}

	protected function isBoxMcpServerEnabled(): bool
	{
		return Feature::getInstance()->isBoxMcpServerEnabled();
	}

	protected function canUseLocalTokens(int $userId): bool
	{
		return (new McpEntryPointAccess())->canUseLocalTokens($userId);
	}
}
