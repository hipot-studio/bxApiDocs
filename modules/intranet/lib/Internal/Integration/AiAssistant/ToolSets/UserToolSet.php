<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\GetInvitationStatusTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\InviteByEmailTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\InviteByPhoneTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\ResendInvitationTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User\BulkInviteTool;

class UserToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'user';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'User Tool Set',
			'Public tool set for direct invitation actions on users: first-time invites, pending-invitation resend, and invitation status or quota checks.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			GetInvitationStatusTool::class,
			InviteByEmailTool::class,
			InviteByPhoneTool::class,
			ResendInvitationTool::class,
			BulkInviteTool::class,
		]);
	}
}
