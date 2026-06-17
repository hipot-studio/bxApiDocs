<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Agents;

use Bitrix\AiAssistant\Definition\Agent\BaseAgent;
use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\SystemPromptDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Department\SearchDepartmentsTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\InviteLink\CreateInviteLinkTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\InviteLink\RevokeInviteLinkTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\BulkInviteTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\GetInvitationStatusTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\InviteByEmailTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\InviteByPhoneTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\ResendInvitationTool;

class UserInviteAgent extends BaseAgent
{
	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	public function getCode(): string
	{
		return 'user_invite_agent';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'User Invite Agent',
			'Agent for inviting new users to the Bitrix24 portal, monitoring invitation status and quotas, '
			. 'resending pending invitations, and managing the current active shared invite link. '
			. 'Not for user management, profile edits, or permission changes.',
		);
	}

	public function getSystemPrompt(): ?SystemPromptDto
	{
		return new SystemPromptDto(
			name: 'UserInviteAgentSystemPrompt',
			promptCode: 'user_invite_agent_system_prompt',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			SearchDepartmentsTool::class,
			CreateInviteLinkTool::class,
			RevokeInviteLinkTool::class,
			GetInvitationStatusTool::class,
			InviteByEmailTool::class,
			InviteByPhoneTool::class,
			ResendInvitationTool::class,
			BulkInviteTool::class,
		]);
	}
}
