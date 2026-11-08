<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\AddUsersToChatTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\CreateGroupChatTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\GetChatMembersTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\GetLastMessagesTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\GetUnreadCountersTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\MarkChatAsReadTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\RemoveUserFromChatTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\SearchGroupChatsByNameTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\SearchMessagesInChatTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\SearchPrivateChatsByNameTool;
use Bitrix\Im\V2\Integration\AiAssistant\Tools\SendMessageTool;
use Bitrix\Im\V2\Integration\AiAssistant\ToolScope;

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

	/**
	 * Unlike getUsesTools(), which is materialized into the 24h DefinitionRegistry cache,
	 * canList()/canRun() are consulted on every request, so flipping the gating options
	 * takes effect immediately regardless of the registry cache.
	 */
	public function canList(int $userId): bool
	{
		return ToolScope::Read->isEnabled() || ToolScope::Write->isEnabled();
	}

	public function canRun(int $userId): bool
	{
		return ToolScope::Read->isEnabled() || ToolScope::Write->isEnabled();
	}

	/**
	 * Always returns the full tool list: the result is materialized into the 24h DefinitionRegistry
	 * cache (ToolManager::callTool() resolves tool classes from that cached map), so a conditional
	 * list would desync the live listing from the cached call map when a gating option is flipped.
	 * Visibility and execution are gated per tool by BaseImTool::canList()/canRun() instead.
	 *
	 * After a deployment that changes this composition, an already warmed registry cache keeps
	 * serving the previous tool list until its TTL expires (up to 24h). This rollout delay is an
	 * accepted compromise: the cache and its key belong to the aiassistant module, and invalidating
	 * it on toolset composition changes is that module's responsibility (handoff passed).
	 */
	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			SearchGroupChatsByNameTool::class,
			SearchPrivateChatsByNameTool::class,
			GetLastMessagesTool::class,
			SearchMessagesInChatTool::class,
			GetChatMembersTool::class,
			GetUnreadCountersTool::class,
			SendMessageTool::class,
			MarkChatAsReadTool::class,
			CreateGroupChatTool::class,
			AddUsersToChatTool::class,
			RemoveUserFromChatTool::class,
		]);
	}

	public function getAdditionalRequiredModules(): array
	{
		return [
			'im',
		];
	}
}
