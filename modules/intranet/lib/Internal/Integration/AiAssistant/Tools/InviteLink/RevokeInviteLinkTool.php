<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\InviteLink;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\BaseTool;
use Bitrix\Intranet\Service\ServiceContainer;

class RevokeInviteLinkTool extends BaseTool
{
	public function canRun(int $userId): bool
	{
		return
			parent::canRun($userId)
			&& $this->isRegisterByLinkAllowed()
			;
	}

	public function getName(): string
	{
		return 'revoke_invite_link';
	}

	public function getDescription(): string
	{
		return
			'Revokes the current active shared invite link by changing the secret used for generating invite tokens. '
			. 'Use this tool to disable the current active shared invite link, not to revoke an arbitrary historical link by ID. '
			. 'After executing this tool, previously generated shared invite links become invalid. '
			. 'To share a new invite link, create a new one using create_invite_link. '
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			ServiceContainer::getInstance()->inviteTokenService()->reCreateSecret();
		}
		catch (McpException $e)
		{
			throw $e;
		}
		catch (\Throwable $e)
		{
			throw new McpException($e->getMessage());
		}

		return [
			'revoked' => true,
			'message' => 'The current active shared invite link has been revoked. To share a link again, create a new invite link.',
		];
	}
}
