<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Tools;

use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Intranet\Entity\Collection\BaseCollection;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Intranet\Integration\HumanResources\Department;
use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;
use Bitrix\Intranet\User;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

abstract class BaseTool extends ToolContract
{
	public function canList(int $userId): bool
	{
		return
			$userId > 0
			&& (new User($userId))->isIntranet()
		;
	}

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

	protected function isRegisterByLinkAllowed(): bool
	{
		return
			Loader::includeModule('socialservices')
			&& Option::get('socialservices', 'new_user_registration_network', 'Y') === 'Y'
		;
	}

	protected function isPhoneInviteAllowed(): bool
	{
		return
			Loader::includeModule('bitrix24')
			&& Option::get('bitrix24', 'phone_invite_allowed', 'N') === 'Y'
		;
	}

	protected function isLocalEmailProgram(): bool
	{
		return
			Loader::includeModule("bitrix24")
			&& Option::get('intranet', 'useInviteLocalEmailProgram', 'N') === 'Y'
		;
	}

	protected function findSingle(
		BaseCollection $collection,
		string $identifier,
		string $valueName,
		callable $outputName,
	): mixed
	{
		return match ($collection->count())
		{
			0 => throw new McpException("{$valueName} with {$identifier} was not found."),
			1 => $collection->first(),
			default => throw new McpException(
				"Found {$collection->count()} {$valueName} entities with {$identifier}: " .
				implode(', ', $collection->map(
					fn($entity) => sprintf('%s (ID %d)', $outputName($entity), $entity->getId())
				))
			),
		};
	}

	protected function resolveDepartmentCollection(int $userId, ?int $departmentId): DepartmentCollection
	{
		$permission = new PermissionInvitation($userId);

		if ($departmentId !== null)
		{
			$departmentCollection = (new Department())->getByIds([$departmentId]);
			if ($departmentCollection->count() > 1)
			{
				throw new McpException("Department with ID {$departmentId} has multiple entries");
			}

			$department = $departmentCollection->first();
			if ($department === null)
			{
				throw new McpException("Department with ID {$departmentId} was not found.");
			}

			if (!$permission->canInviteToDepartment($department))
			{
				throw new McpException("Current user cannot invite employees to department {$departmentId}.");
			}

			return $departmentCollection;
		}

		$departmentCollection = new DepartmentCollection();
		$defaultDepartment = $permission->findFirstPossibleAvailableDepartment();

		if ($defaultDepartment !== null)
		{
			$departmentCollection->add($defaultDepartment);
		}

		return $departmentCollection;
	}

	protected function resolveDepartmentCollectionByIds(int $userId, array $departmentIds): DepartmentCollection
	{
		if (empty($departmentIds))
		{
			return new DepartmentCollection();
		}

		$permission = new PermissionInvitation($userId);
		$departmentCollection = (new Department())->getByIds($departmentIds);

		$foundIds = array_flip(
			$departmentCollection->map(
				static fn($department) => $department->getId(),
			),
		);

		foreach ($departmentIds as $departmentId)
		{
			if (!is_int($departmentId))
			{
				throw new McpException('Each department ID must be an integer.');
			}

			if (!isset($foundIds[$departmentId]))
			{
				throw new McpException("Department with ID {$departmentId} was not found.");
			}
		}

		foreach ($departmentCollection as $department)
		{
			if (!$permission->canInviteToDepartment($department))
			{
				throw new McpException(
					"Current user cannot invite employees to department {$department->getId()}."
				);
			}
		}

		return $departmentCollection;
	}
}
