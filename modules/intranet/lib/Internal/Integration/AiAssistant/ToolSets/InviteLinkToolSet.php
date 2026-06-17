<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\InviteLink\CreateInviteLinkTool;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\InviteLink\RevokeInviteLinkTool;

class InviteLinkToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'invite_link';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Invite Link Tool Set',
			'Public tool set for the current active shared invite link: create or rotate it, or revoke it.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			CreateInviteLinkTool::class,
			RevokeInviteLinkTool::class,
		]);
	}
}
