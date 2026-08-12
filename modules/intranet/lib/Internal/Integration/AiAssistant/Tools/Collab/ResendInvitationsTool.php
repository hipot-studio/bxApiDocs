<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Intranet\Internal\Factory\Message\CollabInvitationMessageFactory;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\InvitationIdsDto;
use Bitrix\Intranet\Public\Facade\Invitation\ReInvitationFacade;
use Bitrix\Intranet\Public\Type\EmailInvitation;
use Bitrix\Intranet\Public\Type\PhoneInvitation;
use Bitrix\Socialnetwork\Collab\Collab;

class ResendInvitationsTool extends BaseCollabTool
{
	public function getName(): string
	{
		return 'resend_invitations';
	}

	public function getDescription(): string
	{
		return
			'Resends one or more current pending project invitations by invitation ID. '
			. 'Use it only for existing pending invitations that were already created in the target project. '
			. 'If an invitation is no longer pending, it will be skipped with its actual current status.'
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
					'description' => 'Invitation IDs to resend. Up to 100 per call.',
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
		$project = $this->resolveProject($userId, $dto->projectId);

		$batch = $this->getCurrentProjectInvitationsBatch(
			projectId: $dto->projectId,
			invitationIds: $dto->invitationIds,
		);
		$items = $batch['items'];
		$this->ensureAllInvitationIdsFound($dto->invitationIds, $items);

		[$queued, $skipped, $failed] = $this->resendInvitations(
			$items,
			$batch['usersById'],
			$batch['invitationTypesByUserId'],
			$project,
		);

		return [
			'projectId' => $dto->projectId,
			'summary' => [
				'requestedCount' => count($dto->invitationIds),
				'queuedCount' => count($queued),
				'skippedCount' => count($skipped),
				'failedCount' => count($failed),
			],
			'queued' => $queued,
			'skipped' => $skipped,
			'failed' => $failed,
		];
	}

	protected function resendInvitations(array $items, array $usersById, array $invitationTypes, Collab $project): array
	{
		$queued = [];
		$skipped = [];
		$failed = [];
		$reInvitationFacade = new ReInvitationFacade($project);

		foreach ($items as $item)
		{
			if (($item['projectInvitation']['isPendingRequest'] ?? false) !== true)
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
				$user = $usersById[(int)$item['inviteeId']] ?? null;
				if (!$user instanceof User)
				{
					throw new McpException("User with ID {$item['inviteeId']} was not found.");
				}

				$queued = $this->reinviteUser(
					$item,
					$user,
					$invitationTypes[(int)$item['inviteeId']] ?? null,
					$queued,
					$project,
					$reInvitationFacade,
				);
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

		return [$queued, $skipped, $failed];
	}

	protected function reinviteUser(
		array $item,
		User $user,
		?string $invitationType,
		array $queued,
		Collab $project,
		ReInvitationFacade $reInvitationFacade,
	): array
	{
		$channel = $this->resolveChannel($user, $invitationType ?? null);
		if ($channel === null)
		{
			throw new McpException(
				"User ID {$user->getId()} has no contact data for resending the invitation."
			);
		}

		if ($channel === InvitationType::PHONE && !$this->isPhoneInviteAllowed())
		{
			throw new McpException('Phone invitations are not available on this portal.');
		}

		if ($user->getInviteStatus() === InvitationStatus::ACTIVE)
		{
			$this->sendCollabInvitationMessage($user, $project, $channel);
		}
		else
		{
			$reInvitationFacade->invite(
				$channel === InvitationType::PHONE
				? new PhoneInvitation($user->getPhoneNumber(), $user->getName(), $user->getLastName())
				: new EmailInvitation($user->getEmail(), $user->getName(), $user->getLastName()),
			);
		}

		$queued[] = [
			'invitationId' => $item['invitationId'],
			'inviteeId' => $item['inviteeId'],
			'channel' => $channel->value,
		];

		return $queued;
	}

	protected function sendCollabInvitationMessage(User $user, Collab $project, InvitationType $channel): void
	{
		$messageFactory = new CollabInvitationMessageFactory($user, $project);

		if ($channel === InvitationType::PHONE)
		{
			$messageFactory->createSmsEvent()->sendImmediately();

			return;
		}

		$messageFactory->createEmailEvent()->sendImmediately();
	}
}
