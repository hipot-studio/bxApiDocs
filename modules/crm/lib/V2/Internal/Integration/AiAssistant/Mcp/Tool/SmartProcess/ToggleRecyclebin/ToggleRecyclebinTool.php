<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ToggleRecyclebin;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToggleTool;

final class ToggleRecyclebinTool extends AbstractToggleTool
{
	protected function getToolName(): string
	{
		return 'toggle_smart_process_recyclebin';
	}

	protected function getToolDescription(): string
	{
		return 'Enables or disables the recycle bin'
			. ' for a CRM smart process (dynamic type).'
			. ' The recycle bin cannot be disabled while it contains items'
			. ' of this smart process; the model returns an error in that case.';
	}

	protected function getFlagName(): string
	{
		return 'allowRecyclebin';
	}

	protected function getFlagDescription(): string
	{
		return 'Enable or disable the recycle bin for items of the smart process.';
	}

	protected function getArgsDtoClass(): string
	{
		return ToggleRecyclebinToolDto::class;
	}

	protected function applyFlag(Type $type, bool $isEnabled): void
	{
		$type->setIsRecyclebinEnabled($isEnabled);
	}

	protected function readFlag(Type $type): bool
	{
		return (bool)$type->getIsRecyclebinEnabled();
	}
}
