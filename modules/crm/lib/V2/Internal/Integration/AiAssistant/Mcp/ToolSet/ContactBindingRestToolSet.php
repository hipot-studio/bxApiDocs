<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;

final class ContactBindingRestToolSet extends AbstractRestToolSet
{
	public function getCode(): string
	{
		return 'crm_rest_v3_contact_binding';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM contact bindings',
			'Set the primary contact of CRM items.',
		);
	}
}
