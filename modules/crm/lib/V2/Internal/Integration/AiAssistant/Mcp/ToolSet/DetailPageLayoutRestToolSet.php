<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;

final class DetailPageLayoutRestToolSet extends AbstractRestToolSet
{
	public function getCode(): string
	{
		return 'crm_rest_v3_detail_page_layout';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM detail page layouts',
			'Read and manage personal and common CRM detail page layouts for supported item types.',
		);
	}
}
