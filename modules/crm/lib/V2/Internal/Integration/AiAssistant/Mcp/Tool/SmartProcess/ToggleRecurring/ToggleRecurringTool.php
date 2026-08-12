<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ToggleRecurring;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToggleTool;

final class ToggleRecurringTool extends AbstractToggleTool
{
	protected function getToolName(): string
	{
		return 'toggle_smart_process_recurring';
	}

	protected function getToolDescription(): string
	{
		return 'Enables or disables recurring (repeating) items'
			. ' for a CRM smart process (dynamic type).';
	}

	protected function getFlagName(): string
	{
		return 'allowRecurring';
	}

	protected function getFlagDescription(): string
	{
		return 'Enable or disable recurring (repeating) items of the smart process.';
	}

	protected function getArgsDtoClass(): string
	{
		return ToggleRecurringToolDto::class;
	}

	protected function applyFlag(Type $type, bool $isEnabled): void
	{
		$type->setIsRecurringEnabled($isEnabled);
	}

	protected function readFlag(Type $type): bool
	{
		return (bool)$type->getIsRecurringEnabled();
	}
}
