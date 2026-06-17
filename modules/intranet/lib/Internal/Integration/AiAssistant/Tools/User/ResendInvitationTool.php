<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Entity\User as EntityUser;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\User\ResendInvitationDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\BaseTool;
use Bitrix\Intranet\Public\Facade\Invitation\ReInvitationFacade;
use Bitrix\Intranet\Public\Type\EmailInvitation;
use Bitrix\Intranet\Public\Type\PhoneInvitation;
use Bitrix\Intranet\Repository\UserRepository;

class ResendInvitationTool extends BaseTool
{
	public function canRun(int $userId): bool
	{
		try
		{
			return (new PermissionInvitation($userId))->canInvite();
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getName(): string
	{
		return 'resend_invitation';
	}

	public function getDescription(): string
	{
		return
			'Resends a pending invitation that has not yet been accepted. '
			. 'Use this tool only for an already invited user, not for a first-time invite. '
			. 'Use exactly one identifier: user ID, email, or phone number. '
			. 'If you know only a name, resolve the target first through monitoring. '
			. 'The tool only works for users with invitation status INVITED or INVITE_AWAITING_APPROVE.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'filterUserId' => [
					'type' => 'integer',
					'description' => 'ID of the invited user. Use exactly one of filterUserId, email, or phoneNumber.',
					'minimum' => 1,
				],
				'email' => [
					'type' => 'string',
					'description' => 'Email of the invited user. Use exactly one of filterUserId, email, or phoneNumber.',
					'format' => 'email',
				],
				'phoneNumber' => [
					'type' => 'string',
					'description' => 'Phone number of the invited user. Use exactly one of filterUserId, email, or phoneNumber. Prefer international format.',
				],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = ResendInvitationDto::fromArray($args);

			$user = $this->resolveUser($dto);
			$this->checkCanResentToUser($user);

			$channel = $this->resolveChannel($dto, $user);
			$this->resendInvitation($user, $channel);

			return [
				'user' => [
					'id' => $user->getId(),
					'email' => $user->getEmail(),
					'phoneNumber' => $user->getPhoneNumber(),
					'name' => $user->getName(),
					'lastName' => $user->getLastName(),
					'fullName' => $user->getFormattedName(),
					'invitationStatus' => $user->getInviteStatus()->value,
				],
				'resent' => true,
				'channel' => $channel->value,
			];
		}
		catch (McpException $e)
		{
			throw $e;
		}
		catch (\Throwable $e)
		{
			throw new McpException($e->getMessage());
		}
	}

	private function resolveUser(ResendInvitationDto $dto): EntityUser
	{
		$userRepository = new UserRepository();

		if ($dto->filterUserId !== null)
		{
			return
				$userRepository->getUserById($dto->filterUserId)
				?? throw new McpException("User with ID {$dto->filterUserId} was not found.")
			;
		}

		if ($dto->email !== null)
		{
			$users = $userRepository->findUsersByLoginsAndEmails([$dto->email]);

			return $this->findSingle(
				$users,
				"email '{$dto->email}'",
				"email",
				static fn ($user) => $user->getFormattedName(),
			);
		}

		$users = $userRepository->findUsersByLoginsAndPhoneNumbers([$dto->phoneNumber]);

		return $this->findSingle(
			$users,
			"phoneNumber '{$dto->phoneNumber}'",
			"phoneNumber",
			static fn ($user) => $user->getFormattedName(),
		);
	}

	private function checkCanResentToUser(EntityUser $user): void
	{
		$status = $user->getInviteStatus();

		if (
			$status !== InvitationStatus::INVITED
			&& $status !== InvitationStatus::INVITE_AWAITING_APPROVE
		)
		{
			throw new McpException(
				"Invitation for user ID {$user->getId()} has already been accepted or cannot be resent."
			);
		}
	}

	private function resolveChannel(ResendInvitationDto $dto, EntityUser $user): InvitationType
	{
		if ($dto->email !== null || $dto->phoneNumber !== null)
		{
			return $dto->email !== null ? InvitationType::EMAIL : InvitationType::PHONE;
		}

		return match (true)
		{
			!empty($user->getEmail()) => InvitationType::EMAIL,
			!empty($user->getPhoneNumber()) => InvitationType::PHONE,
			default => throw new McpException(
				"User ID {$user->getId()} has no email or phone number for resending the invitation."
			),
		};
	}

	private function resendInvitation(EntityUser $user, InvitationType $channel): void
	{
		if ($channel === InvitationType::PHONE && !$this->isPhoneInviteAllowed())
		{
			throw new McpException('Phone invitations are not available on this portal.');
		}

		(new ReInvitationFacade())->invite($this->createInvitation($user, $channel));
	}

	private function createInvitation(EntityUser $user, InvitationType $channel)
	{
		return match($channel)
		{
			InvitationType::PHONE => new PhoneInvitation($user->getPhoneNumber(), $user->getName(), $user->getLastName()),
			InvitationType::EMAIL => new EmailInvitation($user->getEmail(), $user->getName(), $user->getLastName()),
		};
	}
}
