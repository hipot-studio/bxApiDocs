<?php

namespace Bitrix\Crm\Filter\FieldsTransform;

use Bitrix\Crm\Integration\HumanResources;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Filter\Field;
use Bitrix\Main\Web\Json;

/**
 * Transform 'all-users' and 'other-users' filter values to ORM compatible values.
 */
final class UserBasedField
{
	private const STRUCTURE_NODE = 'structure-node';
	private const USER_NODE = 'user';
	private const FIRED_USER_NODE = 'fired-user';
	private const META_USER_NODE = 'meta-user';

	public const DEFAULT_FILTER_FIELDS_TO_TRANSFORM = [
		'ASSIGNED_BY_ID',
		'ACTIVITY_RESPONSIBLE_IDS'
	];

	private const KEEP_ALL_USERS_IN_FILTER = [
		'ACTIVITY_RESPONSIBLE_IDS'
	];

	/**
	 * Transform 'all-users' and 'other-users' filter values to ORM compatible values.
	 * @param array $filter
	 * @param array|null $fieldNames fields to transform. If empty will be user default list
	 * 			self::DEFAULT_FILTER_FIELDS_TO_TRANSFORM
	 * @return void
	 */
	public static function applyTransformWrapper(array &$filter, ?array $fieldNames = null)
	{
		if ($fieldNames === null)
		{
			$fieldNames = self::DEFAULT_FILTER_FIELDS_TO_TRANSFORM;
		}

		$currentUser = CurrentUser::get()->getId();

		$instance = new self();

		$instance->transformAll($filter, $fieldNames, $currentUser);
	}

	/**
	 * @param array $filter
	 * @param string[] $fieldNames
	 * @param int|null $currentUser
	 * @return void
	 */
	public function transformAll(array &$filter, array $fieldNames, ?int $currentUser): void
	{
		foreach ($fieldNames as $fieldName)
		{
			$this->transform($filter, $fieldName, $currentUser);
		}
	}


	public function transform(array &$filter, string $fieldName, ?int $currentUser): void
	{
		if (!isset($filter[$fieldName]))
		{
			return;
		}

		if (!is_array($filter[$fieldName]))
		{
			return;
		}

		if ($this->isAllUsers($filter[$fieldName], $currentUser))
		{
			$filter = $this->allUsers($fieldName, $filter);
		}
		elseif ($this->isOtherUsers($filter[$fieldName], $currentUser))
		{
			$name = '!' . $fieldName;
			$filter[$name] = $currentUser;
			unset($filter[$fieldName]);
		}
	}

	private function isCurrentUserInFilter(array $assignedField, ?int $currentUser): bool
	{
		return $currentUser && in_array($currentUser, $assignedField);
	}

	private function isAllUsers(array $assignedFilter, ?int $currentUser): bool
	{
		if (in_array('all-users', $assignedFilter, true))
		{
			return true;
		}

		if (
			in_array('other-users', $assignedFilter, true)
			&& $this->isCurrentUserInFilter($assignedFilter, $currentUser)
		)
		{
			return true;
		}

		return false;
	}

	private function isOtherUsers(array $assignedFilter, ?int $currentUser): bool
	{
		return (
			in_array('other-users', $assignedFilter, true)
			&& !$this->isCurrentUserInFilter($assignedFilter, $currentUser)
		);
	}

	public function allUsers(string $fieldName, array $filter): array
	{
		if (!in_array($fieldName, self::KEEP_ALL_USERS_IN_FILTER))
		{
			unset($filter[$fieldName]);
		}
		else
		{
			$filter[$fieldName] = [];
		}
		return $filter;
	}

	/**
	 * Finds all 'structure-nodes' in filter array and breaks them down to members of corresponding departments
	 * @param array $filter
	 * @param Field[] $filterFields
	 * @return array
	 * @throws ArgumentException
	 */
	public static function breakDepartmentsToUsers(array $filter, array $filterFields): array
	{
		$fieldsContainingUsers = self::getIdsOfFieldsContainingUsers($filterFields);

		foreach ($filter as $fieldName => $fieldValue)
		{
			if (
				!is_array($fieldValue)
				|| !in_array($fieldName, $fieldsContainingUsers, true)
			)
			{
				continue;
			}

			$departments = [];
			foreach ($fieldValue as $key => $value)
			{
				if ((int)$value !== 0)
				{
					continue;
				}

				try
				{
					$value = Json::decode($value);
				}
				catch (\Throwable $e)
				{
					continue;
				}

				$entityType = $value[0];
				$entityId = $value[1];

				if ($entityType === self::STRUCTURE_NODE)
				{
					$departments[] = $entityId;
				}
				elseif ($entityType === self::USER_NODE || $entityType === self::FIRED_USER_NODE)
				{
					$filter[$fieldName][] = (int)$entityId;
				}
				elseif ($entityType === self::META_USER_NODE)
				{
					$filter[$fieldName][] = $entityId;
				}

				unset($filter[$fieldName][$key]);
			}

			$filter[$fieldName] = array_merge(
				$filter[$fieldName],
				self::getUserIdsFromDepartments($departments),
			);
		}

		return $filter;
	}

	private static function getUserIdsFromDepartments(array $departmentIds): array
	{
		$deepDepartmentIds = [];
		$shallowDepartmentIds = [];
		foreach ($departmentIds as $departmentId)
		{
			$isFlat = str_ends_with($departmentId, ':F');
			if ($isFlat)
			{
				$shallowDepartmentIds[] = substr($departmentId, 0, -2);
			}
			else
			{
				$deepDepartmentIds[] = $departmentId;
			}
		}

		$userIds = [];
		$departmentQueries = HumanResources\DepartmentQueries::getInstance();
		foreach ($shallowDepartmentIds as $departmentId)
		{
			$departmentMembers = $departmentQueries->getUsersByDepartmentId($departmentId, false);

			$userIds = array_merge($userIds, $departmentMembers);
		}

		foreach ($deepDepartmentIds as $departmentId)
		{
			$departmentMembers = $departmentQueries->getUsersByDepartmentId($departmentId, true);

			$userIds = array_merge($userIds, $departmentMembers);
		}

		if (
			empty($userIds)
			&& (!empty($deepDepartmentIds) || !empty($shallowDepartmentIds))
		)
		{
			$userIds[] = -1; // preventing empty filter for empty departments
		}

		return array_unique($userIds);
	}

	/**
	 * @param Field[] $filterFields
	 * @return array
	 */
	private static function getIdsOfFieldsContainingUsers(array $filterFields): array
	{
		$res = [];

		foreach ($filterFields as $field)
		{
			if ($field->getType() !== 'entity_selector')
			{
				continue;
			}

			$entitySelectorEntities = $field->toArray()['params']['dialogOptions']['entities'] ?? [];

			foreach ($entitySelectorEntities as $entitySelectorEntity)
			{
				if ($entitySelectorEntity['id'] === self::STRUCTURE_NODE)
				{
					$res[] = $field->getId();
				}
			}
		}

		self::attachContactsAndCompanyFields($res);

		return $res;
	}

	private static function attachContactsAndCompanyFields(array &$filterFieldsIds): void
	{
		foreach ($filterFieldsIds as $fieldId)
		{
			$filterFieldsIds[] = 'CONTACT_' . $fieldId;
			$filterFieldsIds[] = 'COMPANY_' . $fieldId;
		}
	}
}
