<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\User\InviteByPhoneDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\BaseTool;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\InvitationLimiter;
use Bitrix\Intranet\Internal\Integration\Bitrix24\PortalCreatorService;
use Bitrix\Intranet\Public\Facade\Invitation\IntranetInvitationFacade;
use Bitrix\Intranet\Public\Type\PhoneInvitation;
use Bitrix\Intranet\Repository\UserRepository;

class InviteByPhoneTool extends BaseTool
{
	public function canList(int $userId): bool
	{
		return
			parent::canList($userId)
			&& $this->isPhoneInviteAllowed()
		;
	}

	public function canRun(int $userId): bool
	{
		try
		{
			return
				!(new InvitationLimiter())->isExceeded()
				&& (new PermissionInvitation($userId))->canInvite()
				&& (new PortalCreatorService())->isPortalCreatorEmailConfirmed()
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getName(): string
	{
		return 'invite_by_phone';
	}

	public function getDescription(): string
	{
		return
			'Invites one person to the portal by phone number via SMS for a first-time direct invitation. '
			. 'Use this tool only for one concrete phone number; do not use it for bulk invites, shared invite links, or resending an existing invitation. '
			. 'If you know only the department name, first call search_departments and then pass the selected departmentId here. '
			. 'If a user with this phone number already exists on the portal, the invitation must not be sent. '
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'phoneNumber' => [
					'type' => 'string',
					'description' => 'Employee phone number. Prefer international format.',
				],
				'firstName' => [
					'type' => 'string',
					'description' => 'First name of the invited employee. Optional.',
				],
				'lastName' => [
					'type' => 'string',
					'description' => 'Last name of the invited employee. Optional.',
				],
				'departmentId' => [
					'type' => 'integer',
					'description' => 'Department ID for the invited employee. Optional. If omitted, the first available department for the current user will be used.',
					'minimum' => 1,
				],
			],
			'required' => ['phoneNumber'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = InviteByPhoneDto::fromArray($args);

			$existingUsers = (new UserRepository())->findUsersByLoginsAndPhoneNumbers([$dto->phoneNumber]);
			if (!$existingUsers->empty())
			{
				$existingUser = $existingUsers->first();

				throw new McpException(
					"User with phoneNumber '{$dto->phoneNumber}' already exists on the portal"
					. ($existingUser ? " (ID {$existingUser->getId()})" : '.')
				);
			}

			$departmentCollection = $this->resolveDepartmentCollection($userId, $dto->departmentId);

			$invitationFacade = new IntranetInvitationFacade($departmentCollection);
			$user = $invitationFacade->invite(
				new PhoneInvitation(
					$dto->phoneNumber,
					$dto->firstName,
					$dto->lastName,
				),
			);

			return [
				'user' => [
					'id' => $user->getId(),
					'phoneNumber' => $user->getPhoneNumber(),
					'name' => $user->getName(),
					'lastName' => $user->getLastName(),
					'fullName' => $user->getFormattedName(),
					'invitationStatus' => $user->getInviteStatus()->value,
				],
				'invited' => true,
				'departmentId' => $departmentCollection->first()?->getId(),
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
}
