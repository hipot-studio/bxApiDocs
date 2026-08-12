<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\InvitationIdsDto;
use Bitrix\Socialnetwork\Helper\Workgroup;

class RevokeInvitationsTool extends BaseCollabTool
{
	public function getName(): string
	{
		return 'revoke_invitations';
	}

	public function getDescription(): string
	{
		return
			'Revokes one or more current pending project invitations by invitation ID. '
			. 'After revocation, the recipients will no longer be able to join the project through those pending invitations. '
			. 'Only current pending invitations can be revoked.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'projectId' => [
					'type' => 'integer',
					'description' => 'Target project ID.',
					'minimum' => 1,
				],
				'invitationIds' => [
					'type' => 'array',
					'description' => 'Invitation IDs to revoke. Up to 100 per call.',
					'items' => [
						'type' => 'integer',
						'minimum' => 1,
					],
				],
			],
			'required' => ['projectId', 'invitationIds'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructuredInternal(int $userId, ...$args): array
	{
		$dto = InvitationIdsDto::fromArray($args);
		$this->resolveProject($userId, $dto->projectId);

		$items = $this->getCurrentProjectInvitations($dto->projectId, $dto->invitationIds);
		$this->ensureAllInvitationIdsFound($dto->invitationIds, $items);

		[$revoked, $skipped, $failed] = $this->deleteInvitationRequests($items, $dto, $userId);

		return [
			'projectId' => $dto->projectId,
			'summary' => [
				'requestedCount' => count($dto->invitationIds),
				'revokedCount' => count($revoked),
				'skippedCount' => count($skipped),
				'failedCount' => count($failed),
			],
			'revoked' => $revoked,
			'skipped' => $skipped,
			'failed' => $failed,
		];
	}

	protected function deleteInvitationRequests(array $items, InvitationIdsDto $dto, int $currentUserId): array
	{
		$revoked = [];
		$skipped = [];
		$failed = [];

		foreach ($items as $item)
		{
			if (!($item['projectInvitation']['isPendingRequest'] ?? false))
			{
				$skipped[] = [
					'invitationId' => $item['invitationId'],
					'inviteeId' => $item['inviteeId'],
					'actualStatus' => $item['status'],
				];
				continue;
			}

			try
			{
				Workgroup::deleteOutgoingRequest([
					'userId' => (int)$item['inviteeId'],
					'groupId' => $dto->projectId,
					'currentUserId' => $currentUserId,
				]);

				$revoked[] = [
					'invitationId' => $item['invitationId'],
					'inviteeId' => $item['inviteeId'],
				];
			}
			catch (\Throwable $e)
			{
				$failed[] = [
					'invitationId' => $item['invitationId'],
					'inviteeId' => $item['inviteeId'],
					'reason' => $e->getMessage(),
				];
			}
		}

		return [$revoked, $skipped, $failed];
	}
}
