<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;

final class ContactRestToolSet extends AbstractRestToolSet
{
	public function getCode(): string
	{
		return 'crm_rest_v3_contact';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM contacts',
			'Read and manage contacts, calculate aggregates, and inspect field definitions.',
		);
	}
}
