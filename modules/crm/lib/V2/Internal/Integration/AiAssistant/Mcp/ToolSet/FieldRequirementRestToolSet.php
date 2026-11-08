<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access\FieldRequirementAccessChecker;
use Bitrix\Rest\V3\Mcp\Access\MethodAccessCheckerInterface;

final class FieldRequirementRestToolSet extends AbstractRestToolSet
{
	public function getCode(): string
	{
		return 'crm_rest_v3_field_requirement';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM field requirements',
			'Read, set and clear requirement settings for CRM fields.',
		);
	}

	protected function getAccessChecker(): MethodAccessCheckerInterface
	{
		return new FieldRequirementAccessChecker();
	}
}
