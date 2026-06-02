<?php declare(strict_types=1);

namespace Bitrix\AI\Services;

use Bitrix\Ui\Public\Services\Copilot;

/**
 * @deprecated Use \Bitrix\Ui\Public\Services\Copilot\CopilotNameService instead
 */
class CopilotNameService
{
	/**
	 * @deprecated Use \Bitrix\Ui\Public\Services\Copilot\CopilotNameService::getCopilotName() instead
	 */
	public function getCopilotName(): string
	{
		return (new Copilot\CopilotNameService())->getCopilotName();
	}
}
