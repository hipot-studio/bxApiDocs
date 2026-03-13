<?php

namespace Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\Main\Engine\CurrentUser as User;
use Bitrix\Main\Loader;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\MemberSubordinateRelationType;
use Bitrix\HumanResources\Service\Container as HrContainer;

class CurrentUser extends UrlFilter
{
	private User $user;
	private ?array $userDepartments = null;

	public function __construct(Dashboard $dashboard, ?string $filterId = null)
	{
		parent::__construct($dashboard, $filterId);

		$this->user = User::get();
	}

	public static function getFilterType(): string
	{
		return 'filter_company_structure';
	}

	public function getFormatted(): string
	{
		$filters = $this->dashboard->getNativeFiltersConfig();
		if (empty($filters))
		{
			return '';
		}

		$filterConfig = current(array_filter($filters, fn ($filter) => isset($filter['id']) && $filter['id'] === $this->filterId));
		if (empty($filterConfig) || !is_array($filterConfig))
		{
			return '';
		}

		$defaultValues = $filterConfig['defaultDataMask']['filterState']['value'] ?? [];

		if (!isset($filterConfig['targets']) || !is_array($filterConfig['targets']))
		{
			return '';
		}

		$targetDataset = $filterConfig['targets'][0];
		if (empty($targetDataset) || !is_array($targetDataset))
		{
			return '';
		}

		$targetColumn = $targetDataset['column']['name'] ?? null;
		if (!$targetColumn)
		{
			return '';
		}

		[$filterStateValues, $userIds] = $this->getValuesToApply($defaultValues);

		if (empty($filterStateValues))
		{
			$userIds = [$this->user->getId()];
			$filterStateValues = [
				[
					'id' => 'user_' . $this->user->getId(),
					'entityId' => 'user',
					'label' => "{$this->user->getFirstName()} {$this->user->getLastName()}",
				],
			];
		}

		$urlTemplateFilter = <<<FILTER
			#FILTER_ID#:(
				extraFormData:(
					filters:!((
						col:#COLUMN_ID#,
						op:IN,
						val:!(#USER_IDS#)
					))
				),
				filterState:(
					value:!(#FILTER_VALUES#)
				),
				id:#FILTER_ID#,
				ownState:()
			)
			FILTER;

		return strtr(
			$urlTemplateFilter,
			[
				'#FILTER_ID#' => $this->getCode(),
				'#COLUMN_ID#' => $targetColumn,
				'#USER_IDS#' => implode(',', array_unique($userIds)),
				'#FILTER_VALUES#' => $this->formatFilterValues($filterStateValues),
			]
		);
	}

	private function getValuesToApply(array $defaultValues): array
	{
		if (!$defaultValues)
		{
			return [[], []];
		}

		if (!Loader::includeModule('humanresources'))
		{
			return [[], []];
		}

		$currentUserDepartments = $this->getCurrentUserDepartments();
		$filterValues = [];
		$userIds = [];
		foreach ($defaultValues as $item)
		{
			if (!is_array($item) || !isset($item['id'], $item['entityId']))
			{
				continue;
			}

			$entityId = $item['entityId'];
			$id = $item['id'];

			if ($entityId === 'department' && preg_match('/^(?:only_users_department_|all_department_)(\d+)$/', $id, $matches))
			{
				$departmentId = (int)$matches[1];
				$withSubDeps = str_starts_with($id, 'all_department_');
				$availableDepartment = current(array_filter($currentUserDepartments, static fn ($availableDepartment) => $departmentId === $availableDepartment['id']));
				if ($availableDepartment)
				{
					$departmentUserIds = $this->getDepartmentUserIds($departmentId, $withSubDeps && $availableDepartment['subDepsAvailable']);
					foreach ($departmentUserIds as $userId)
					{
						$userIds[] = $userId;
					}

					if ($withSubDeps && $availableDepartment['subDepsAvailable'] === false)
					{
						$item['id'] = 'only_users_department_' . $departmentId;
						$item['label'] = HrContainer::getNodeService()->getNodeInformation($availableDepartment['id'])->name;
					}
					$filterValues[] = $item;
				}
			}
			elseif ($entityId === 'user' && preg_match('/^user_(\d+)$/', $id, $matches))
			{
				$userId = (int)$matches[1];
				if ($this->isUserAvailable((int)$this->user->getId(), $userId))
				{
					$userIds[] = $userId;
					$filterValues[] = $item;
				}
			}
		}

		return [
			$filterValues,
			$userIds,
		];
	}

	private function getCurrentUserDepartments(): array
	{
		if ($this->userDepartments !== null)
		{
			return $this->userDepartments;
		}

		$this->userDepartments = [];
		$userId = (int)$this->user->getId();

		if ($userId <= 0)
		{
			return $this->userDepartments;
		}

		$memberCollection = HrContainer::getNodeMemberRepository()->findAllByEntityIdAndEntityType(
			$userId,
			MemberEntityType::USER
		);

		$headRoleId = HrContainer::getRoleHelperService()->getHeadRoleId();

		foreach ($memberCollection as $member)
		{
			$departmentId = $member->nodeId;
			$userRoles = $member->roles ?? [];

			$isHead = $headRoleId && in_array($headRoleId, $userRoles, false);

			$this->userDepartments[] = [
				'id' => $departmentId,
				'subDepsAvailable' => $isHead,
			];
			if ($isHead)
			{
				$childNodes = HrContainer::getNodeService()->getNodeChildNodes($departmentId);
				foreach ($childNodes as $childNode)
				{
					$this->userDepartments[] = ['id' => $childNode->id, 'subDepsAvailable' => true];
				}
			}
		}

		return $this->userDepartments;
	}

	private function isUserAvailable(int $userId, int $employeeId): bool
	{
		if ($userId === $employeeId)
		{
			return true;
		}

		$userNodeMembers = HrContainer::getNodeMemberRepository()->findAllByEntityIdAndEntityType(
			$userId,
			MemberEntityType::USER,
		);
		$employeeNodeMembers = HrContainer::getNodeMemberRepository()->findAllByEntityIdAndEntityType(
			$employeeId,
			MemberEntityType::USER,
		);
		foreach ($userNodeMembers as $userNodeMember)
		{
			foreach ($employeeNodeMembers as $employeeNodeMember)
			{
				if (is_null($userNodeMember->id) || is_null($employeeNodeMember->id))
				{
					continue;
				}
				$relation = HrContainer::getNodeMemberService()->getMemberSubordination(
					$userNodeMember->id,
					$employeeNodeMember->id,
				);
				if ($relation === MemberSubordinateRelationType::RELATION_HIGHER || $relation === MemberSubordinateRelationType::RELATION_EQUAL)
				{
					return true;
				}
			}
		}

		return false;
	}

	private function getDepartmentUserIds(int $departmentId, bool $withAllChildNodes = false): array
	{
		if ($departmentId <= 0)
		{
			return [];
		}

		$userCollection = HrContainer::getNodeMemberService()->getAllEmployees($departmentId, $withAllChildNodes);

		return $userCollection->getEntityIds();
	}

	private function formatFilterValues(array $filterValues): string
	{
		$formatted = [];

		foreach ($filterValues as $item)
		{
			$id = $item['id'];
			$entityId = $item['entityId'];
			$label = str_replace(' ', '+', $item['label'] ?? '');

			$formatted[] = "(id:'{$id}',entityId:'{$entityId}',label:'{$label}')";
		}

		return implode(',', $formatted);
	}
}
