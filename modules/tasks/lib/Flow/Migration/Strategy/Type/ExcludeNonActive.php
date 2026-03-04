<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Migration\Strategy\Type;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Tasks\Flow\Internal\DI\Container;
use Bitrix\Tasks\Flow\Migration\Strategy\MigrationStrategyInterface;
use Bitrix\Tasks\Flow\Migration\Strategy\Trait\SaveFlowMembersTrait;
use Bitrix\Tasks\Flow\Migration\Strategy\Result\StrategyResult;
use Bitrix\Tasks\Flow\FlowRegistry;
use Bitrix\Tasks\Flow\Internal\Entity\Role;
use Bitrix\Tasks\Flow\Provider\FlowMemberFacade;
use Bitrix\Tasks\Flow\Provider\UserStatusProvider;
use Bitrix\Tasks\Flow\Provider\DepartmentExistsProvider;

class ExcludeNonActive implements MigrationStrategyInterface
{
	use SaveFlowMembersTrait;

	private FlowMemberFacade $memberFacade;
	private UserStatusProvider $userProvider;
	private DepartmentExistsProvider $departmentProvider;

	public function __construct()
	{
		$this->memberFacade = ServiceLocator::getInstance()->get('tasks.flow.member.facade');
		$this->userProvider = Container::getInstance()->get(UserStatusProvider::class);
		$this->departmentProvider = Container::getInstance()->get(DepartmentExistsProvider::class);
	}

	public function migrate(int $flowId, ?Role $excludedRole = null, ?string $excludedAccessCode = null): StrategyResult
	{
		$result = new StrategyResult();

		if (null === $excludedRole)
		{
			$result->addError(new Error("Role is not specified."));

			return $result;
		}

		if (!AccessCode::isValid($excludedAccessCode))
		{
			$result->addError(new Error("Invalid access code: {$excludedAccessCode}."));

			return $result;
		}

		$flow = FlowRegistry::getInstance()->get($flowId);
		if (!$flow)
		{
			$result->addError(new Error("Flow {$flowId} not found."));

			return $result;
		}

		$currentMembersByRole = $this->memberFacade->getMemberAccessCodesByRole($flowId, $excludedRole);
		$withoutExcludedCode = $this->excludeByCode($currentMembersByRole, $excludedAccessCode);
		$onlyActive = $this->excludeNonActive($withoutExcludedCode);

		if (empty($onlyActive))
		{
			return $result;
		}

		return $this->saveFlowMembers($flowId, $onlyActive, $excludedRole);
	}

	/**
	 * @param string[] $memberList
	 * @return string[]
	 */
	private function excludeByCode(array $memberList, string $excluded): array
	{
		$filter = static function ($member) use ($excluded) {
			$isDepartmentExcluded = str_starts_with($excluded, 'D');

			if (!$isDepartmentExcluded)
			{
				return $member !== $excluded;
			}

			$departmentId = (new AccessCode($excluded))->getEntityId();

			return ($member !== "D{$departmentId}") && ($member !== "DR{$departmentId}");
		};

		return array_filter($memberList, $filter);
	}

	/**
	 * @param string[] $members
	 * @return string[]
	 */
	public function excludeNonActive(array $members): array
	{
		$splitAccessCodes = $this->splitAccessCodesByType($members);

		$memberCodesMap = $splitAccessCodes['map'];
		$userIds = $splitAccessCodes['userIds'];
		$departmentIds = $splitAccessCodes['departmentIds'];

		$activeUsers = $this->filterActiveUsers($userIds);
		$activeDepartments = $this->filterExistsDepartments($departmentIds);

		$onlyActive = [];
		foreach ($memberCodesMap as $code => $member)
		{
			$memberId = $member->getEntityId();

			if (
				isset($activeDepartments[$memberId])
				&& ($member->getEntityType() === AccessCode::TYPE_DEPARTMENT)
			)
			{
				$onlyActive[] = $code;

				continue;
			}

			if (isset($activeUsers[$memberId]))
			{
				$onlyActive[] = $code;
			}
		}

		return $onlyActive;
	}

	/**
	 * @param string[] $accessCodes
	 * @return array{map: array<string, AccessCode>, userIds: int[], departmentIds: int[]}
	 */
	private function splitAccessCodesByType(array $accessCodes): array
	{
		$map = [];
		$userIds = [];
		$departmentIds = [];

		foreach ($accessCodes as $code)
		{
			$map[$code] = new AccessCode($code);

			$entityType = $map[$code]->getEntityType();
			$entityId = $map[$code]->getEntityId();

			if ($entityType === AccessCode::TYPE_DEPARTMENT)
			{
				$departmentIds[] = $entityId;
			}
			else
			{
				$userIds[] = $entityId;
			}
		}

		return [
			'map' => $map,
			'userIds' => $userIds,
			'departmentIds' => $departmentIds,
		];
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, true>
	 */
	private function filterActiveUsers(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$activeIds = $this->userProvider->filterActive($userIds);

		return array_flip($activeIds);
	}

	/**
	 * @param int[] $departmentIds
	 * @return array<int, true>
	 */
	private function filterExistsDepartments(array $departmentIds): array
	{
		if (empty($departmentIds))
		{
			return [];
		}

		$existsIds = $this->departmentProvider->filterExists($departmentIds);

		return array_flip($existsIds);
	}
}
