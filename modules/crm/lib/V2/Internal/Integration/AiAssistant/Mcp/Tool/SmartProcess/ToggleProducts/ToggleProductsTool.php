<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ToggleProducts;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToggleTool;

final class ToggleProductsTool extends AbstractToggleTool
{
	protected function getToolName(): string
	{
		return 'toggle_smart_process_products';
	}

	protected function getToolDescription(): string
	{
		return 'Enables or disables catalog product linking'
			. ' for a CRM smart process (dynamic type).';
	}

	protected function getFlagName(): string
	{
		return 'allowProducts';
	}

	protected function getFlagDescription(): string
	{
		return 'Enable or disable catalog product linking for items of the smart process.';
	}

	protected function getArgsDtoClass(): string
	{
		return ToggleProductsToolDto::class;
	}

	protected function applyFlag(Type $type, bool $isEnabled): void
	{
		$type->setIsLinkWithProductsEnabled($isEnabled);
	}

	protected function readFlag(Type $type): bool
	{
		return (bool)$type->getIsLinkWithProductsEnabled();
	}
}
