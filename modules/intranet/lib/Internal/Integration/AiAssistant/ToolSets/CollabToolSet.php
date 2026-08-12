<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
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

class CollabToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'collab';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Collab Tool Set',
			'Public tool set for guest invitations inside projects (collabs): '
			. 'send, list, resend, revoke, manage project invite links, and promote accepted guests to employees.',
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
			PromoteInviteeToEmployeeTool::class,
		]);
	}
}
