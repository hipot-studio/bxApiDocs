<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Invitation;

use Bitrix\Intranet\Entity\Department;
use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;
use Bitrix\Intranet\Internal\Exception\PortalSetupIncompleteException;
use Bitrix\Intranet\Repository\HrDepartmentRepository;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class AllowedDepartmentResolver
{
	public const ACCESS_DENIED_ERROR_CODE = 'INTRANET_INVITE_DEPARTMENT_ACCESS_DENIED';
	public const STRUCTURE_UNAVAILABLE_ERROR_CODE = 'INTRANET_INVITE_DEPARTMENT_STRUCTURE_UNAVAILABLE';
	public const LIMIT_EXCEEDED_ERROR_CODE = 'INTRANET_INVITE_DEPARTMENT_LIMIT_EXCEEDED';
	public const DEPARTMENT_IDS_KEY = 'departmentIds';
	public const MAX_REQUESTED_DEPARTMENT_COUNT = 100;

	public function __construct(
		private readonly HrDepartmentRepository $departmentRepository,
		private readonly PermissionInvitation $permissionInvitation,
	)
	{
	}

	public static function createByCurrentUser(): self
	{
		return new self(
			new HrDepartmentRepository(),
			PermissionInvitation::createByCurrentUser(),
		);
	}

	public function resolve(mixed $departmentId): Result
	{
		$result = new Result();
		$requestedIds = $this->extractDepartmentIds($departmentId);

		if (count($requestedIds) > self::MAX_REQUESTED_DEPARTMENT_COUNT)
		{
			return $result->addError(self::createError(
				self::LIMIT_EXCEEDED_ERROR_CODE,
				['#COUNT#' => (string)self::MAX_REQUESTED_DEPARTMENT_COUNT],
			));
		}

		$allowedIds = $this->filterAllowedToInvite($requestedIds);

		if ($allowedIds !== [])
		{
			return $result->setData([self::DEPARTMENT_IDS_KEY => $allowedIds]);
		}

		try
		{
			$fallbackId = $this->permissionInvitation->findFirstPossibleAvailableDepartment()?->getIblockSectionId();
		}
		catch (PortalSetupIncompleteException)
		{
			$fallbackId = null;
		}

		if ($fallbackId === null)
		{
			return $result->addError(self::createError(self::ACCESS_DENIED_ERROR_CODE));
		}

		return $result->setData([self::DEPARTMENT_IDS_KEY => [$fallbackId]]);
	}

	public static function createStructureUnavailableResult(): Result
	{
		return (new Result())->addError(self::createError(self::STRUCTURE_UNAVAILABLE_ERROR_CODE));
	}

	private static function createError(string $code, array $replace = []): Error
	{
		return new Error(Loc::getMessage($code, $replace), $code);
	}

	/**
	 * @return int[]
	 */
	private function extractDepartmentIds(mixed $departmentId): array
	{
		$values = is_array($departmentId) ? $departmentId : [$departmentId];
		$ids = [];

		foreach ($values as $value)
		{
			if (is_scalar($value) && is_numeric($value) && (int)$value > 0)
			{
				$ids[] = (int)$value;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @param int[] $iblockSectionIds
	 * @return int[]
	 */
	private function filterAllowedToInvite(array $iblockSectionIds): array
	{
		if ($iblockSectionIds === [])
		{
			return [];
		}

		$departments = [];
		$nodeIds = [];
		foreach ($this->departmentRepository->findAllByIblockIds($iblockSectionIds) as $department)
		{
			$iblockSectionId = $department instanceof Department ? $department->getIblockSectionId() : null;
			if ($iblockSectionId === null)
			{
				continue;
			}

			$departments[$iblockSectionId] = $department;
			if ($department->getId() !== null)
			{
				$nodeIds[] = $department->getId();
			}
		}

		$confirmedNodeIds = array_flip(
			$this->permissionInvitation->filterConfirmedInvitableDepartmentIds($nodeIds),
		);

		$allowedIds = [];
		foreach ($iblockSectionIds as $iblockSectionId)
		{
			$department = $departments[$iblockSectionId] ?? null;
			if ($department === null)
			{
				continue;
			}

			if (
				isset($confirmedNodeIds[$department->getId()])
				|| $this->permissionInvitation->canInviteToDepartment($department)
			)
			{
				$allowedIds[] = $iblockSectionId;
			}
		}

		return $allowedIds;
	}
}
