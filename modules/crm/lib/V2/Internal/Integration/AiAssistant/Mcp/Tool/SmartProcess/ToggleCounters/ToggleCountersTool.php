<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ToggleCounters;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToggleTool;

final class ToggleCountersTool extends AbstractToggleTool
{
	protected function getToolName(): string
	{
		return 'toggle_smart_process_counters';
	}

	protected function getToolDescription(): string
	{
		return 'Enables or disables counters for a CRM smart process (dynamic type).';
	}

	protected function getFlagName(): string
	{
		return 'allowCounters';
	}

	protected function getFlagDescription(): string
	{
		return 'Enable or disable counters for items of the smart process.';
	}

	protected function getArgsDtoClass(): string
	{
		return ToggleCountersToolDto::class;
	}

	protected function applyFlag(Type $type, bool $isEnabled): void
	{
		$type->setIsCountersEnabled($isEnabled);
	}

	protected function readFlag(Type $type): bool
	{
		return (bool)$type->getIsCountersEnabled();
	}
}
