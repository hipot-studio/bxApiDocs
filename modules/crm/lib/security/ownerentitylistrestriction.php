<?php

namespace Bitrix\Crm\Security;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\Security\QueryBuilder\OptionsBuilder;
use Bitrix\Crm\Security\QueryBuilder\Result;
use Bitrix\Crm\Security\QueryBuilder\Result\RawQueryResult;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\DB\SqlExpression;
use CCrmOwnerType;

/**
 * Builds ORM filter fragments that restrict a list query of entities bound to CRM owners
 * according to read permissions of a user. Any owner type with a permission model is supported
 * (leads, contacts, companies, as well as document types such as deals, invoices, quotes and
 * dynamic type items); the caller passes the subset its binding table actually stores.
 */
final class OwnerEntityListRestriction
{
	private const DENY_FILTER_VALUE = [0];

	public function __construct(private readonly UserPermissions $userPermissions)
	{
	}

	/**
	 * Returns a filter fragment that restricts rows by their direct CRM owners.
	 *
	 * Returns null if no restriction is required (admin or full access to every requested owner type).
	 * Otherwise returns an ORM filter fragment to be combined with the current filter via AND
	 * (including a deny fragment ['@{idField}' => [0]] when the user has no access at all).
	 *
	 * @param int[] $ownerTypeIds Owner types stored by the target binding table; each must have a CRM
	 *        permission model (e.g. Lead, Contact, Company, Deal, Invoice, Quote, dynamic type items).
	 * @param string $typeFieldName Name of the owner type binding field of the target table.
	 * @param string $idFieldName Name of the owner id binding field of the target table.
	 * @return array|null
	 */
	public function buildFilter(array $ownerTypeIds, string $typeFieldName, string $idFieldName): ?array
	{
		if ($this->userPermissions->isAdmin())
		{
			return null;
		}

		$ownerTypeIds = array_values(array_unique(array_map('intval', $ownerTypeIds)));

		$branches = [];
		$fullAccessTypeCount = 0;
		foreach ($ownerTypeIds as $ownerTypeId)
		{
			$result = $this->buildOwnerTypeQueryResult($ownerTypeId);

			$isRestricted = true;
			if ($result->hasAccess() && !$result->hasRestrictions())
			{
				$branches[] = ['=' . $typeFieldName => $ownerTypeId];
				$isRestricted = false;
				$fullAccessTypeCount++;
			}
			elseif ($result->hasAccess())
			{
				$branches[] = [
					'=' . $typeFieldName => $ownerTypeId,
					'@' . $idFieldName => $result->getSqlExpression(),
				];
			}

			if ($ownerTypeId === CCrmOwnerType::Company && $isRestricted && $this->canReadMyCompanies())
			{
				$branches[] = [
					'=' . $typeFieldName => CCrmOwnerType::Company,
					'@' . $idFieldName => new SqlExpression($this->getMyCompaniesSql()),
				];
			}
		}

		if (!empty($ownerTypeIds) && $fullAccessTypeCount === count($ownerTypeIds))
		{
			return null;
		}

		if (empty($branches))
		{
			return ['@' . $idFieldName => self::DENY_FILTER_VALUE];
		}

		if (count($branches) === 1)
		{
			return $branches[0];
		}

		return array_merge(['LOGIC' => 'OR'], $branches);
	}

	/**
	 * Returns a filter fragment for entities bound to a requisite (e.g. bank details):
	 * rows are restricted to requisites whose owners (contacts, companies) are readable by the user.
	 *
	 * Null/deny semantics are the same as in buildFilter().
	 *
	 * @param string $typeFieldName Name of the owner type binding field of the target table.
	 * @param string $idFieldName Name of the owner id binding field of the target table.
	 * @return array|null
	 */
	public function buildRequisiteBoundFilter(string $typeFieldName, string $idFieldName): ?array
	{
		if ($this->userPermissions->isAdmin())
		{
			return null;
		}

		$ownerTypeIds = [CCrmOwnerType::Contact, CCrmOwnerType::Company];
		$ownerSqlConditions = [];
		$fullAccessTypeCount = 0;
		foreach ($ownerTypeIds as $ownerTypeId)
		{
			$result = $this->buildOwnerTypeQueryResult($ownerTypeId);

			$isRestricted = true;
			if ($result->hasAccess() && !$result->hasRestrictions())
			{
				$ownerSqlConditions[] = "(ENTITY_TYPE_ID = {$ownerTypeId})";
				$isRestricted = false;
				$fullAccessTypeCount++;
			}
			elseif ($result->hasAccess())
			{
				$ownerSqlConditions[] = "(ENTITY_TYPE_ID = {$ownerTypeId} AND ENTITY_ID IN ({$result->getSql()}))";
			}

			if ($ownerTypeId === CCrmOwnerType::Company && $isRestricted && $this->canReadMyCompanies())
			{
				$ownerSqlConditions[]
					= '(ENTITY_TYPE_ID = ' . CCrmOwnerType::Company
					. " AND ENTITY_ID IN ({$this->getMyCompaniesSql()}))";
			}
		}

		if ($fullAccessTypeCount === count($ownerTypeIds))
		{
			return null;
		}

		if (empty($ownerSqlConditions))
		{
			return ['@' . $idFieldName => self::DENY_FILTER_VALUE];
		}

		$requisiteSql
			= 'SELECT ID FROM ' . RequisiteTable::getTableName()
			. ' WHERE ' . implode(' OR ', $ownerSqlConditions);

		return [
			'=' . $typeFieldName => CCrmOwnerType::Requisite,
			'@' . $idFieldName => new SqlExpression($requisiteSql),
		];
	}

	private function buildOwnerTypeQueryResult(int $ownerTypeId): Result
	{
		$permissionEntityTypes = (new PermissionEntityTypeHelper($ownerTypeId))
			->getAllPermissionEntityTypesForEntity()
		;

		$options = (new OptionsBuilder(new RawQueryResult()))
			->setSkipCheckOtherEntityTypes(true)
			->build()
		;

		return $this->userPermissions
			->itemsList()
			->createQueryBuilder($permissionEntityTypes, $options)
			->build()
		;
	}

	private function canReadMyCompanies(): bool
	{
		return $this->userPermissions->myCompany()->canReadBaseFields();
	}

	private function getMyCompaniesSql(): string
	{
		return 'SELECT ID FROM ' . CompanyTable::getTableName() . " WHERE IS_MY_COMPANY = 'Y'";
	}
}
