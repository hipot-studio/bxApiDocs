<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\SearchGroupChatsByNameTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\SearchPrivateChatsByNameTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\SendMessageTool;

class ImToolSet extends BaseToolSet
{

	public function getCode(): string
	{
		return 'im_mcp';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'IM MCP',
			'Public IM MCP Tool Set',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto(
			tools: [
				SendMessageTool::class,
				SearchGroupChatsByNameTool::class,
				SearchPrivateChatsByNameTool::class,
			],
		);
	}

	public function getAdditionalRequiredModules(): array
	{
		return [
			'im',
		];
	}
}
