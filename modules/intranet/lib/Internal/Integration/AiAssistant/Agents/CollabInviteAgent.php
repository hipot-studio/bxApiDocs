<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Agents;

use Bitrix\AiAssistant\Definition\Agent\BaseAgent;
use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\SystemPromptDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\InvitationLink\ActivateInvitationLinkTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\InvitationLink\DeactivateInvitationLinkTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\InvitationLink\GetInvitationLinkTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\InvitationLink\RegenerateInvitationLinkTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\ListInvitationsTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\PromoteInviteeToEmployeeTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\ResendInvitationsTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\RevokeInvitationsTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\SearchCollabsTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab\SendInvitationsTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Department\SearchDepartmentsTool;

class CollabInviteAgent extends BaseAgent
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
		return 'collab_invite_agent';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Collab Invite Agent',
			'Agent for managing external user (guest) invitations inside Bitrix24 projects (collabs): '
			. 'send invitations by email or phone, track invitation statuses, resend or revoke pending invitations, '
			. 'manage the project invitation link, and promote accepted guests to employees. '
			. 'Not for portal invitations, project management, or removing accepted project members.',
		);
	}

	public function getSystemPrompt(): ?SystemPromptDto
	{
		return new SystemPromptDto(
			name: 'CollabInviteAgentSystemPrompt',
			promptCode: 'collab_invite_agent_system_prompt',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			SearchCollabsTool::class,
			SendInvitationsTool::class,
			ListInvitationsTool::class,
			ResendInvitationsTool::class,
			RevokeInvitationsTool::class,
			GetInvitationLinkTool::class,
			RegenerateInvitationLinkTool::class,
			DeactivateInvitationLinkTool::class,
			ActivateInvitationLinkTool::class,
			SearchDepartmentsTool::class,
			PromoteInviteeToEmployeeTool::class,
		]);
	}
}
