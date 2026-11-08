<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Internal\Access\Model\UserModel;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Access\Service\TemplateAuthorshipProvider;
use Bitrix\Bizproc\Internal\Model\PermissionTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

/**
 * Data-level companion of {@see \Bitrix\Bizproc\Public\Service\TemplateAccessService}: turns a user's
 * effective permission scope into an ORM filter over WorkflowTemplateTable.ID. The grid and the REST
 * list both mix in this filter so they narrow to the same area from a single source of truth.
 */
class TemplateAccessProvider
{
	private const INLINE_SCOPE_LIMIT = 100;

	/** Fail-closed filter: matches no template (IDs are positive) and never widens to "all". */
	public const FAIL_CLOSED_FILTER = ['=ID' => -1];

	/**
	 * Key carrying the area as a nested condition instead of a flat map over ID. It is not a field name: a
	 * consumer has to pass the value to Query::where() rather than copy it into an array filter.
	 *
	 * The condition is nested and never a flat ['LOGIC' => 'OR', ...] because both consumers merge the result
	 * into an already built condition set, where a top-level OR would lift their own restrictions on template
	 * type, system code and document type.
	 */
	public const SCOPE_CONDITION_KEY = '__BIZPROC_TEMPLATE_SCOPE_CONDITION';

	/**
	 * ORM filter selecting the templates the user effectively has $permission on:
	 *  - portal admin / scope "all" (-1) => [] (no ID restriction);
	 *  - small selective scope => ['@ID' => [t1, t2, ...]];
	 *  - large selective scope => ['@ID' => ACL subquery];
	 *  - scope holding "own" (-2) => [SCOPE_CONDITION_KEY => ConditionTree] over the enumeration and authorship;
	 *  - empty scope (no role, or 0 selected templates) => FAIL_CLOSED_FILTER (never "all").
	 *
	 * For EDIT the scope also unions PUBLISH (publish dominates edit) so list visibility stays in sync
	 * with {@see TemplateAccessService::canEdit()}.
	 *
	 * The branch order matters and answers the same states as the check side
	 * ({@see \Bitrix\Bizproc\Internal\Access\Rule\BaseRule}). The portal switch is not read here: the "own"
	 * sentinel is already subtracted at the source of the scope
	 * ({@see \Bitrix\Bizproc\Internal\Access\Model\UserModel::getPermissionMulti}), so a scope made of it alone
	 * arrives empty with the switch off and falls into the fail-closed branch.
	 *
	 * @throws ArgumentException for a toggler permission (e.g. CREATE) which carries no template scope.
	 */
	public function getEntityFilter(?int $userId, int $permission = PermissionDictionary::BIZPROC_TEMPLATE_EDIT): array
	{
		$descriptor = PermissionDictionary::getPermission((string)$permission);
		if (($descriptor['type'] ?? null) === PermissionDictionary::TYPE_TOGGLER)
		{
			throw new ArgumentException('Entity filter is not applicable to a toggler permission: ' . $permission);
		}

		$controller = $this->controller($userId);
		if ($controller->isAdmin())
		{
			return [];
		}

		$scope = $this->resolveScope($controller, $permission);
		if (!$scope)
		{
			return self::FAIL_CLOSED_FILTER;
		}

		if (in_array(PermissionDictionary::VALUE_VARIATION_ALL, $scope, true))
		{
			return [];
		}

		// Only a positive value is a template ID, so sentinels and any other non-positive value
		// leave before the enumeration is built - and therefore before it is measured against
		// INLINE_SCOPE_LIMIT: a threshold counting them would not count what is being filtered.
		$templateIds = array_values(array_filter(
			$scope,
			static fn ($value): bool => (int)$value > 0,
		));

		$ownCondition = in_array(PermissionDictionary::VALUE_VARIATION_OWN, $scope, true)
			? $this->getOwnCondition($controller)
			: null
		;

		if ($ownCondition === null)
		{
			return $templateIds
				? $this->getSelectiveScopeFilter($controller, $templateIds, $permission)
				: self::FAIL_CLOSED_FILTER
			;
		}

		return [
			self::SCOPE_CONDITION_KEY => $this->getScopeCondition($controller, $templateIds, $permission, $ownCondition),
		];
	}

	/**
	 * The authorship half of the own area, taken from the definition shared with the check side: two
	 * copies of it would let a template show up in the list while the action on it is refused.
	 *
	 * Authorship needs a positive user id on both sides, so an anonymous caller gets no own area at all and
	 * falls back to the plain branches - the same answer {@see TemplateAuthorshipProvider::isOwn()} gives.
	 */
	private function getOwnCondition(AccessController $controller): ?ConditionTree
	{
		$authorId = $controller->getUser()->getUserId();
		if ($authorId <= 0)
		{
			return null;
		}

		return Query::filter()
			->where('CREATED_BY', $authorId)
			->where(TemplateAuthorshipProvider::ownScopeCondition())
		;
	}

	/**
	 * Authorship is a disjunction with the id enumeration, not an addition to it: a scope made of the "own"
	 * sentinel alone degenerates the enumeration to empty, and its holder would see nothing.
	 */
	private function getScopeCondition(
		AccessController $controller,
		array $templateIds,
		int $permission,
		ConditionTree $ownCondition,
	): ConditionTree
	{
		$ids = $templateIds ? $this->getSelectiveScopeIds($controller, $templateIds, $permission) : null;
		if ($ids === null)
		{
			return $ownCondition;
		}

		return Query::filter()
			->logic('or')
			->whereIn('ID', $ids)
			->where($ownCondition)
		;
	}

	/**
	 * @return int[] effective scope values; [-1] means "all"; [] means empty / no scope.
	 */
	private function resolveScope(AccessController $controller, int $permission): array
	{
		/** @var UserModel $user */
		$user = $controller->getUser();

		$permissions = $this->getScopePermissions($permission);

		$merged = [];
		foreach ($permissions as $code)
		{
			$scope = $user->getPermissionMulti((string)$code);
			if ($scope === null)
			{
				continue;
			}

			if (in_array(PermissionDictionary::VALUE_VARIATION_ALL, $scope, true))
			{
				return [PermissionDictionary::VALUE_VARIATION_ALL];
			}

			foreach ($scope as $value)
			{
				$merged[$value] = $value;
			}
		}

		return array_values($merged);
	}

	private function getSelectiveScopeFilter(AccessController $controller, array $scope, int $permission): array
	{
		$ids = $this->getSelectiveScopeIds($controller, $scope, $permission);

		return $ids === null ? self::FAIL_CLOSED_FILTER : ['@ID' => $ids];
	}

	/**
	 * @return array|SqlExpression|null ID source of the selective area; null when a large area cannot be
	 * narrowed at all (the user carries no role) and therefore selects nothing.
	 */
	private function getSelectiveScopeIds(
		AccessController $controller,
		array $scope,
		int $permission,
	): array|SqlExpression|null
	{
		if (count($scope) <= self::INLINE_SCOPE_LIMIT)
		{
			return array_values($scope);
		}

		$roleIds = $this->getRoleIds($controller);
		if (!$roleIds)
		{
			return null;
		}

		// VALUE > 0 keeps sentinels out of the subquery by definition, which is why the authorship condition
		// is put above it and never inside.
		$subQuery = PermissionTable::query()
			->setSelect(['VALUE'])
			->whereIn('ROLE_ID', $roleIds)
			->whereIn('PERMISSION_ID', $this->getScopePermissions($permission))
			->where('VALUE', '>', 0)
		;

		return new SqlExpression($subQuery->getQuery());
	}

	private function getScopePermissions(int $permission): array
	{
		return $permission === PermissionDictionary::BIZPROC_TEMPLATE_EDIT
			? [PermissionDictionary::BIZPROC_TEMPLATE_EDIT, PermissionDictionary::BIZPROC_TEMPLATE_PUBLISH]
			: [$permission];
	}

	/** @return int[] */
	protected function getRoleIds(AccessController $controller): array
	{
		/** @var UserModel $user */
		$user = $controller->getUser();

		return array_values(array_unique(array_filter(
			array_map('intval', $user->getRoles()),
			static fn (int $roleId): bool => $roleId > 0,
		)));
	}

	protected function controller(?int $userId): AccessController
	{
		return $userId === null
			? AccessController::getCurrent()
			: AccessController::getInstance($userId);
	}
}
