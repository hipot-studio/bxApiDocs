<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Collab;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Dto\Invitation\UserInvitationDto;
use Bitrix\Intranet\Dto\Invitation\UserInvitationDtoCollection;
use Bitrix\Intranet\Entity\Type\Email;
use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\Collab\SendInvitationsDto;
use Bitrix\Intranet\Internal\Integration\Extranet\ExtranetService;
use Bitrix\Intranet\Service\UseCase\Invitation\BulkInviteUsersToCollabAndPortal;

class SendInvitationsTool extends BaseCollabTool
{
	public function canRun(int $userId): bool
	{
		try
		{
			return
				parent::canRun($userId)
				&& $this->isInvitationAdmissionAllowed()
				&& $this->extranetService->isCollaberInvitationEnabled();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getName(): string
	{
		return 'send_invitations';
	}

	public function getDescription(): string
	{
		return
			'Sends new external invitations into a specific project by email or phone. '
			. 'If you know only the collab name, first call search_collabs and then pass the selected projectId here. '
			. 'Use this tool only for first-time invitation delivery to specific recipients in one project. '
			. 'Each recipient may contain email or phoneNumber. '
			. 'If both are provided, the tool tries email first and falls back to phoneNumber when email is invalid. '
			. 'The response returns the created or reused invitees, failed recipients with reasons, and their current project invitation records.'
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
				'contacts' => [
					'type' => 'array',
					'description' => 'Recipients to invite. Up to 100 per call.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'email' => [
								'type' => 'string',
								'description' => 'Recipient email address. Optional if phoneNumber is provided.',
							],
							'phoneNumber' => [
								'type' => 'string',
								'description' => 'Recipient phone number. Optional if email is provided. Prefer international format.',
							],
							'firstName' => [
								'type' => 'string',
								'description' => 'Recipient first name. Optional.',
							],
							'lastName' => [
								'type' => 'string',
								'description' => 'Recipient last name. Optional.',
							],
						],
						'anyOf' => [
							['required' => ['email']],
							['required' => ['phoneNumber']],
						],
						'additionalProperties' => false,
					],
				],
			],
			'required' => ['projectId', 'contacts'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructuredInternal(int $userId, ...$args): array
	{
		$dto = SendInvitationsDto::fromArray($args);
		$this->resolveProject($userId, $dto->projectId);

		$builtRecipients = $this->buildRecipients($dto);
		$invitees = $this->getInvitees($builtRecipients['recipients'], $dto, $userId);
		$failed = [...$builtRecipients['failedRecipients'], ...$invitees['failed']];

		return [
			'summary' => [
				'requestedCount' => count($dto->recipients),
				'queuedCount' => count($invitees['invitees']),
				'failedCount' => count($failed),
				'emailCount' => $builtRecipients['emailCount'],
				'phoneCount' => $builtRecipients['phoneCount'],
			],
			'projectId' => $dto->projectId,
			'invitees' => $invitees['invitees'],
			'failed' => $failed,
		];
	}

	/**
	 * @return array{
	 *  emailCount: int,
	 *  phoneCount: int,
	 *  recipients: list<array{contact: string, channel: string, invitation: UserInvitationDto}>,
	 *  failedRecipients: list<array{contact: string, channel: string, reason: string}>
	 * }
	 */
	protected function buildRecipients(SendInvitationsDto $dto): array
	{
		$emailCount = 0;
		$phoneCount = 0;
		$recipients = [];
		$failedRecipients = [];

		foreach ($dto->recipients as $recipient)
		{
			$isPhoneInvitation = !empty($recipient['phoneNumber']) && empty($recipient['email']);
			$channel = $isPhoneInvitation ? InvitationType::PHONE->value : InvitationType::EMAIL->value;
			$contact = $recipient['email'] ?? $recipient['phoneNumber'];
			$email = !$isPhoneInvitation ? new Email($recipient['email']) : null;
			$phone = $isPhoneInvitation ? new Phone($recipient['phoneNumber']) : null;

			if ($isPhoneInvitation)
			{
				$phoneCount++;

				if (!$this->isPhoneInviteAllowed())
				{
					$failedRecipients[] = [
						'contact' => $contact,
						'channel' => $channel,
						'reason' => 'Phone invitations are not available on this portal.',
					];

					continue;
				}
			}
			else
			{
				$emailCount++;
			}

			$recipients[] = [
				'contact' => $contact,
				'channel' => $channel,
				'invitation' => new UserInvitationDto(
					name: $recipient['firstName'],
					lastName: $recipient['lastName'],
					phone: $phone,
					email: $email,
				),
			];
		}

		return [
			'emailCount' => $emailCount,
			'phoneCount' => $phoneCount,
			'recipients' => $recipients,
			'failedRecipients' => $failedRecipients,
		];
	}

	/**
	 * @param list<array{contact: string, channel: string, invitation: UserInvitationDto}> $recipients
	 * @return array{
	 *  invitees: list<array{userId: int, portalStatus: mixed, projectInvitation: ?array}>,
	 *  failed: list<array{contact: string, channel: string, reason: string}>
	 * }
	 */
	protected function getInvitees(array $recipients, SendInvitationsDto $dto, int $userId): array
	{
		if (empty($recipients))
		{
			return [
				'invitees' => [],
				'failed' => [],
			];
		}

		$invitees = new UserInvitationDtoCollection();

		foreach ($recipients as $recipient)
		{
			$invitees->add($recipient['invitation']);
		}

		try
		{
			$invitationResult = $this->sendInvitation($dto, $invitees, $userId);
		}
		catch (\Throwable $exception)
		{
			return [
				'invitees' => [],
				'failed' => $this->buildFailedRecipients($recipients, $exception->getMessage()),
			];
		}

		$projectInvitationsByUserId = $this->getProjectInvitationsByUserId(
			$dto->projectId,
			$invitationResult['invitedUserIds'],
		);

		return [
			'invitees' => $this->buildInvitees($invitationResult['invitedUsers'], $projectInvitationsByUserId),
			'failed' => [],
		];
	}

	/**
	 * @param list<array{contact: string, channel: string}> $recipients
	 * @return list<array{contact: string, channel: string, reason: string}>
	 */
	protected function buildFailedRecipients(array $recipients, string $reason): array
	{
		$failed = [];

		foreach ($recipients as $recipient)
		{
			$failed[] = [
				'contact' => $recipient['contact'],
				'channel' => $recipient['channel'],
				'reason' => $reason,
			];
		}

		return $failed;
	}

	protected function getProjectInvitationsByUserId(int $projectId, array $invitedUserIds): array
	{
		if (empty($invitedUserIds))
		{
			return [];
		}

		return array_column(
			$this->getCurrentProjectInvitations($projectId, inviteeIds: $invitedUserIds),
			null,
			'inviteeId',
		);
	}

	protected function buildInvitees(array $invitedUsers, array $projectInvitationsByUserId): array
	{
		$invitees = [];

		foreach ($invitedUsers as $userData)
		{
			$invitedUserId = (int)$userData['id'];

			$invitees[] = [
				'userId' => $invitedUserId,
				'portalStatus' => $userData['status'] ?? null,
				'projectInvitation' => $projectInvitationsByUserId[$invitedUserId] ?? null,
			];
		}

		return $invitees;
	}

	/**
	 * @return array{
	 *  invitedUsers: list<array{id: int|string, status?: mixed}>,
	 *  invitedUserIds: list<int>
	 * }
	 */
	protected function sendInvitation(SendInvitationsDto $dto, UserInvitationDtoCollection $collection, int $userId): array
	{
		$result = (new BulkInviteUsersToCollabAndPortal($userId))->execute($dto->projectId, $collection);
		if (!$result->isSuccess())
		{
			throw new McpException($this->formatResultErrors($result));
		}

		$invitedUsers = $result->getData();
		$invitedUserIds = array_values(
			array_unique(
				array_map(
					static fn (array $userData): int => (int)$userData['id'],
					$invitedUsers,
				)
			)
		);

		return [
			'invitedUsers' => $invitedUsers,
			'invitedUserIds' => $invitedUserIds,
		];
	}
}
