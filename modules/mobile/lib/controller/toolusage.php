<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Mobile\Menu\ToolUsage\ToolUsage as ToolUsageService;

final class ToolUsage extends JsonController
{
	/**
	 * @ajaxAction mobile.ToolUsage.process
	 * @return array
	 */
	#[CloseSession]
	public function processAction(): array
	{
		return (new ToolUsageService())->processOnceForPeriod();
	}
}
