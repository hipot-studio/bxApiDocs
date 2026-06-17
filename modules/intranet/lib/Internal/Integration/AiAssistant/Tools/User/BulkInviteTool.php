<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Enum\InvitationType;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\User\BulkInviteDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\BaseTool;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\InvitationLimiter;
use Bitrix\Intranet\Internal\Integration\Bitrix24\PortalCreatorService;
use Bitrix\Intranet\Public\Facade\Invitation\IntranetInvitationFacade;
use Bitrix\Intranet\Public\Type\BaseInvitation;
use Bitrix\Intranet\Public\Type\EmailInvitation;
use Bitrix\Intranet\Public\Type\PhoneInvitation;
use Bitrix\Intranet\Repository\UserRepository;

class BulkInviteTool extends BaseTool
{
	public function canRun(int $userId): bool
	{
		return
			parent::canRun($userId)
			&& !(new InvitationLimiter())->isExceeded()
			&& (new PortalCreatorService())->isPortalCreatorEmailConfirmed()
		;
	}

	public function getName(): string
	{
		return 'bulk_invite';
	}

	public function getDescription(): string
	{
		return
			'Sends first-time portal invitations to multiple contacts at once. '
			. 'Use this tool only when you already have a list of two or more concrete contacts; do not use it for one person, for resending existing invitations, or for shared invite links. '
			. 'Each contact may contain email or phoneNumber, and email is preferred if both are provided. '
			. 'If you know only the department name, first call search_departments and then pass the selected departmentId here. '
			. 'The response contains invited users, skipped contacts already on the portal, and failed contacts with reasons.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'contacts' => [
					'type' => 'array',
					'description' => 'List of contacts to invite. Each contact must contain at least one of email or phoneNumber. If both are provided, email is used.',
					'items' => [
						'type' => 'object',
						'properties' => [
							'email' => [
								'type' => 'string',
								'description' => 'Contact email address. Optional if phoneNumber is provided.',
								'format' => 'email',
							],
							'phoneNumber' => [
								'type' => 'string',
								'description' => 'Contact phone number. Optional if email is provided. Prefer international format.',
							],
							'firstName' => [
								'type' => 'string',
								'description' => 'First name. Optional.',
							],
							'lastName' => [
								'type' => 'string',
								'description' => 'Last name. Optional.',
							],
						],
						'additionalProperties' => false,
					],
				],
				'departmentId' => [
					'type' => 'integer',
					'description' => 'Department ID for all invited contacts. Optional. If omitted, the first available department for the current user will be used.',
					'minimum' => 1,
				],
			],
			'required' => ['contacts'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = BulkInviteDto::fromArray($args);
			$departmentCollection = $this->resolveDepartmentCollection($userId, $dto->departmentId);
			$invitationFacade = new IntranetInvitationFacade($departmentCollection);
			$userRepository = new UserRepository();

			$invitedUsers = [];
			$alreadyOnPortal = [];
			$failed = [];
			$emailCount = 0;
			$phoneCount = 0;

			foreach ($dto->contacts as $contact)
			{
				try
				{
					$invitation = $this->createInvitation($contact);
					$channel = $invitation instanceof EmailInvitation ? InvitationType::EMAIL : InvitationType::PHONE;

					if ($channel === InvitationType::EMAIL)
					{
						$emailCount++;
					}
					else
					{
						$phoneCount++;
					}

					if ($channel === InvitationType::PHONE && !$this->isPhoneInviteAllowed())
					{
						$failed[] = $this->buildFailedItem(
							$contact,
							$channel,
							'phone invitations are not available on this portal.',
						);

						continue;
					}

					if (!$invitation->isValid())
					{
						$failed[] = $this->buildFailedItem(
							$contact,
							$channel,
							$channel === InvitationType::EMAIL
								? 'Invalid email address.'
								: 'Invalid phone number.',
						);

						continue;
					}

					$blockingUsers = $channel === InvitationType::EMAIL
						? $userRepository
							->findUsersByLoginsAndEmails([$invitation->getLogin()])
							->filter(static fn(User $user) => !$user->isEmail() && !$user->isShop())
						: $userRepository->findUsersByLoginsAndPhoneNumbers([$invitation->getLogin()])
					;

					if (!$blockingUsers->empty())
					{
						$activeUsers = $blockingUsers->filter(
							static fn(User $user) => $user->getActive() === true
						);

						$user = $this->findSingle(
							!$activeUsers->empty() ? $activeUsers : $blockingUsers,
							'active user with '.$channel->value.' '.$invitation->getLogin(),
							'user',
							static fn($user) => $user->getName(),
						);

						$userData = [
							'channel' => $channel->value,
							'user' => [
								'id' => $user->getId(),
								...($channel === InvitationType::EMAIL ? [InvitationType::EMAIL->value => $user->getEmail()] : []),
								...($channel === InvitationType::PHONE ? [InvitationType::PHONE->value => $user->getPhoneNumber()] : []),
							],
						];

						if (!$activeUsers->empty())
						{
							$alreadyOnPortal[] = $userData;

							continue;
						}

						$failed[] = [
							'reason' => 'A fired user with this contact already exists on the portal.',
							...$userData,
						];

						continue;
					}

					$user = $invitationFacade->invite($invitation);
					$invitedUsers[] = [
						'channel' => $channel->value,
						'user' => $this->mapInvitedUser($user),
					];
				}
				catch (\Throwable $e)
				{
					$failed[] = $this->buildFailedItem(
						$contact,
						!empty($contact['email']) ? InvitationType::EMAIL : InvitationType::PHONE,
						$e->getMessage(),
					);
				}
			}

			return [
				'summary' => [
					'requestedCount' => count($dto->contacts),
					'invitedCount' => count($invitedUsers),
					'alreadyOnPortalCount' => count($alreadyOnPortal),
					'failedCount' => count($failed),
					'emailCount' => $emailCount,
					'phoneCount' => $phoneCount,
				],
				'departmentId' => $departmentCollection->first()?->getId(),
				'invitedUsers' => $invitedUsers,
				'alreadyOnPortal' => $alreadyOnPortal,
				'failed' => $failed,
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

	private function createInvitation(array $contact): BaseInvitation
	{
		if (!empty($contact['email']))
		{
			return new EmailInvitation(
				$contact['email'],
				$contact['firstName'],
				$contact['lastName'],
				formType: 'mass',
			);
		}

		return new PhoneInvitation(
			$contact['phoneNumber'],
			$contact['firstName'],
			$contact['lastName'],
			formType: 'mass',
		);
	}

	private function mapInvitedUser(User $user): array
	{
		return [
			'id' => $user->getId(),
			'email' => $user->getEmail(),
			'phoneNumber' => $user->getPhoneNumber(),
			'name' => $user->getName(),
			'lastName' => $user->getLastName(),
			'fullName' => $user->getFormattedName(),
			'invitationStatus' => $user->getInviteStatus()->value,
		];
	}

	private function buildFailedItem(array $contact, InvitationType $channel, string $reason): array
	{
		return [
			'contact' => $contact['email'] ?? $contact['phoneNumber'],
			'channel' => $channel->value,
			'reason' => $reason,
		];
	}
}
