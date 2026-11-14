<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\ToolSet\ProductGroup;

final class SettingsRestToolSet extends AbstractRestToolSet
{
	public function getCode(): string
	{
		return 'crm_rest_v3_settings';
	}

	public function getProductGroup(): ?ProductGroup
	{
		return ProductGroup::Crm;
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM settings',
			'List and read CRM settings available to you, inspect their value schemas, and update settings you can change.',
		);
	}
}
