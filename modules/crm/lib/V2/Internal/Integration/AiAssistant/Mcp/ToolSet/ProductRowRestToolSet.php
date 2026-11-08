<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;

final class ProductRowRestToolSet extends AbstractRestToolSet
{
	public function getCode(): string
	{
		return 'crm_rest_v3_product_row';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM product rows',
			'Read and manage product rows of CRM items and inspect the rows available for payment.',
		);
	}
}
