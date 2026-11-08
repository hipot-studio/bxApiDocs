<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;

final class TrackingRestToolSet extends AbstractRestToolSet
{
	public function getCode(): string
	{
		return 'crm_rest_v3_tracking';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM end-to-end analytics',
			'Read, add, and delete tracking traces of the end-to-end analytics: the source, channels,'
				. ' visited pages, and UTM tags that attribute CRM items.',
		);
	}
}
