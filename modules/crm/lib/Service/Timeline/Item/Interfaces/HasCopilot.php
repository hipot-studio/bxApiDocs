<?php

namespace Bitrix\Crm\Service\Timeline\Item\Interfaces;

use Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\BaseButton;

interface HasCopilot
{
	public function getCopilotButton(): ?BaseButton;
}
