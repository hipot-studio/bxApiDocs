<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ToggleDocumentsGenerator;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToggleTool;

final class ToggleDocumentsGeneratorTool extends AbstractToggleTool
{
	protected function getToolName(): string
	{
		return 'toggle_smart_process_documents_generator';
	}

	protected function getToolDescription(): string
	{
		return 'Enables or disables the document print generator'
			. ' for a CRM smart process (dynamic type).';
	}

	protected function getFlagName(): string
	{
		return 'allowDocumentsGenerator';
	}

	protected function getFlagDescription(): string
	{
		return 'Enable or disable the document print generator for items of the smart process.';
	}

	protected function getArgsDtoClass(): string
	{
		return ToggleDocumentsGeneratorToolDto::class;
	}

	protected function applyFlag(Type $type, bool $isEnabled): void
	{
		$type->setIsDocumentsEnabled($isEnabled);
	}

	protected function readFlag(Type $type): bool
	{
		return (bool)$type->getIsDocumentsEnabled();
	}
}
