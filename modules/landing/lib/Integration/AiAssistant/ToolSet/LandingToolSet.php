<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Landing\Integration\AiAssistant\Registry\LandingSiteToolRegistry;

class LandingToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'landing';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Landing Site Tool Set',
			'Landing site lifecycle management tools.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto(LandingSiteToolRegistry::getToolClasses());
	}

	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

}
