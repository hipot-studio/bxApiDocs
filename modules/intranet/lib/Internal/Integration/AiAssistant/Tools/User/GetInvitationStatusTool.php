<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\User;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Bitrix24\License;
use Bitrix\Bitrix24\LicenseScanner\Manager;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Enum\InvitationStatus;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Dto\User\GetInvitationStatusDto;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\BaseTool;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\InvitationLimiter;
use Bitrix\Intranet\Internal\Integration\Humanresources\DepartmentRepository;
use Bitrix\Intranet\Repository\UserRepository;
use Bitrix\Intranet\User;
use Bitrix\Main\Loader;

class GetInvitationStatusTool extends BaseTool
{
	public function canRun(int $userId): bool
	{
		return true;
	}

	public function getName(): string
	{
		return 'get_invitation_status';
	}

	public function getDescription(): string
	{
		return
			'Returns read-only invitation status information for invited users and portal invitation quota data. '
			. 'Use it for monitoring, not for sending or resending invitations. '
			. 'You can filter the results by invitation status, user email, department name, department ID, or user ID. '
			. 'If no filters are provided, the status of all invited users will be returned. '
			. 'The response also includes license and quota usage information.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'invitationStatus' => [
					'type' => 'string',
					'description' => 'Filter by invitation status. Optional. If not provided, all statuses will be returned.',
					'enum' => [InvitationStatus::INVITED->value, InvitationStatus::INVITE_AWAITING_APPROVE->value],
				],
				'userEmail' => [
					'type' => 'string',
					'description' => 'Email of the user to check invitation status for. Optional. Use either filterUserId or userEmail. If both are provided, filterUserId takes precedence. If both not provided, the status of all users will be returned.',
					'format' => 'email',
				],
				'filterUserId' => [
					'type' => 'integer',
					'description' => 'ID of the user to check invitation status for. Optional. Use either filterUserId or userEmail. If both are provided, filterUserId takes precedence. If both not provided, the status of all users will be returned.',
					'minimum' => 1,
				],
				'departmentId' => [
					'type' => 'integer',
					'description' => 'Department ID to filter invitation status by. Optional. Use either departmentId or departmentName. If both are provided, departmentId takes precedence.',
				],
				'departmentName' => [
					'type' => 'string',
					'description' => 'Department name to filter invitation status by. Optional. Use either departmentName or departmentId. If both are provided, departmentId takes precedence.',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'description' => 'Maximum number of users to return. Default is 20.',
				],
				'offset' => [
					'type' => 'integer',
					'minimum' => 0,
					'description' => 'Offset for pagination. Default is 0.',
				],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		try
		{
			$dto = GetInvitationStatusDto::fromArray($args);
			$departmentId = $this->resolveDepartmentId($userId, $dto);
			$userRepository = new UserRepository();
			$currentUser = new User($userId);

			$users = $userRepository->findUsersByInvitationStatusFilter(
				[$dto->invitationStatus],
				$dto->userEmail,
				$dto->filterUserId,
				$departmentId,
				$dto->limit,
				$dto->offset,
			);

			$totalCount = $userRepository->countUsersByInvitationStatusFilter(
				[$dto->invitationStatus],
				$dto->userEmail,
				$dto->filterUserId,
				$departmentId,
			);

			$users = $this->mapUsers($users);

			return [
				'status' => [
					'users' => empty($users) ? 'No invited users were found for the specified filters.' : $users,
					'license_quota' => [
						'invited_by_current_user' => $this->buildInvitedByCurrentUser($currentUser),
						'cloudUsage' => $this->buildCloudUsage(),
					],
				],
				'total' => $totalCount,
				'limit' => $dto->limit,
				'offset' => $dto->offset,
				'hasMore' => ($dto->offset + $dto->limit) < $totalCount,
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

	private function resolveDepartmentId(int $userId, GetInvitationStatusDto $dto): ?int
	{
		if ($dto->departmentId)
		{
			return $dto->departmentId;
		}

		if (!$dto->departmentName)
		{
			return null;
		}

		$departments = (new DepartmentRepository())->searchAvailableDepartmentsByName(
			$userId,
			$dto->departmentName,
		);

		return $this->findSingle(
			$departments,
			"department name '{$dto->departmentName}'",
			'departments',
			static fn($department) => $department->getName(),
		)?->getId();
	}

	private function mapUsers(UserCollection $users): array
	{
		return $users->map(static function($user) {
			return [
				'id' => $user->getId(),
				'name' => $user->getName(),
				'email' => $user->getEmail(),
				'invitationStatus' => $user->getInviteStatus()->value,
			];
		});
	}

	private function buildInvitedByCurrentUser(User $currentUser): array
	{
		return [
			'invitation_count' => $currentUser->getInvitationCounterValue(),
			'waiting_confirmation_count' => $currentUser->getWaitConfirmationCounterValue(),
			'description' => 'invitation_count returns the number of users invited by the current user; if the current user is an admin, it returns the total number of invited users.',
		];
	}

	private function buildCloudUsage(): array
	{
		$cloudUsage = [
			'is_invitation_limit_exceeded' => (new InvitationLimiter())->isExceeded(),
			'description' => 'a portal-wide invitation limit for all users, not a per-user limit. ',
		];

		if (Loader::includeModule('bitrix24'))
		{
			$licenseCode = License::getCurrent()->getCode();
			$manager = Manager::getInstance();
			$invitationDailyLimiter = $manager->getInvitationDailyLimiter();
			$target = $invitationDailyLimiter->getTargetValue($licenseCode);
			$current = $invitationDailyLimiter->getCurrentValue();

			$userLimiter = $manager->getUserLimiter();

			$cloudUsage += [
				'daily_invitations_limit' => (string)($target ?? 'unlimited'),
				'current_day_invited' => $current,
				'free_daily_invitations' =>
					$target !== null
					? (string)(max(0, $target - $current))
					: 'unlimited'
				,
				'user_portal_limit' => $userLimiter->getTargetValue($licenseCode),
				'user_portal_current' => $userLimiter->getCurrentValue(),
			];
		}
		else
		{
			$cloudUsage += [
				'user_limit' => 'unlimited',
			];
		}

		return $cloudUsage;
	}
}
