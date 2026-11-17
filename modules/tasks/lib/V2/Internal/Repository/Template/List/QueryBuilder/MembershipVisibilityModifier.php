<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\Access\Permission\PermissionDictionary;
use Bitrix\Tasks\Access\Permission\TasksTemplatePermissionTable;
use Bitrix\Tasks\Internals\Task\Template\TemplateMemberTable;
use Bitrix\Tasks\Provider\UserProviderTrait;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\AccessChecker;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

/**
 * Row visibility of the "Regular tasks" section list (Q-1, ALG-S3, AC-S-004).
 *
 * The section is a personal slice: `viewerId` is always the current user, even on someone
 * else's personal page. A row is visible when BOTH hold for him:
 *   1. the rule is his own - he is a member of any role (`b_tasks_template_member`:
 *      originator, responsible, accomplice, auditor), the author, or an explicit grant on
 *      the template was issued to him;
 *   2. he may read the template - the same conditions {@see \Bitrix\Tasks\Access\Rule\TemplateReadRule}
 *      checks: an individual grant, or the department / non-department view permission
 *      (an administrator reads any template, so no read condition is added for him).
 *
 * The first condition is what keeps the section personal instead of listing every rule the
 * permissions of the viewer let him read; the second one keeps the list equal to what the card
 * can actually open, so a row never ends in an access error. Editing is not affected: membership
 * grants no write right, changes still go through `TemplateAccessService::canSave()` (AC-S-005).
 *
 * The grant branch is what the role selector cannot show: a rule the viewer holds a grant on
 * without being its member has no role of his, so it lands in "All roles" only - the four role
 * slices do not add up to it. That is a deliberate product choice, not an oversight.
 *
 * The value is an array `['userId' => int]` (the viewer from the server context).
 * Membership is matched with EXISTS and the grant with a single LEFT JOIN deduplicated by
 * DISTINCT, so several member rows or grants never multiply the row.
 */
class MembershipVisibilityModifier extends BaseFilterModifier
{
	use UserProviderTrait;

	private bool $permissionsJoined = false;

	public function modify(Query $query): Query
	{
		$viewerId = is_array($this->value) ? (int)($this->value['userId'] ?? 0) : 0;
		if ($viewerId <= 0)
		{
			return $query;
		}

		$this->executorId = $viewerId;

		$query = $query->where($this->buildOwnFilter($query, $viewerId));

		if ($this->getUserModel()->isAdmin())
		{
			return $query;
		}

		$readFilter = $this->buildReadFilter($query, new AccessChecker($viewerId));
		if ($readFilter === null)
		{
			// Not a single read branch - the viewer may read no template at all.
			return $query->whereNull(Field::Id->value);
		}

		return $query->where($readFilter);
	}

	/**
	 * The rule belongs to the viewer: he is a member, the author, or holds an explicit grant.
	 */
	private function buildOwnFilter(Query $query, int $viewerId): ConditionTree
	{
		$filter = Query::filter()->logic(ConditionTree::LOGIC_OR);

		$subQuery = TemplateMemberTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $viewerId)
			->where('TEMPLATE_ID', new SqlExpression('%s'))
		;

		$filter->whereExpr("EXISTS({$subQuery->getQuery()})", ['ID']);

		// The author is normally his own originator member; the branch covers the legacy rules
		// whose member rows were never written.
		$filter->where(Field::CreatedBy->value, $viewerId);

		$grantFilter = $this->buildGrantFilter($query);
		if ($grantFilter !== null)
		{
			$filter->where($grantFilter);
		}

		return $filter;
	}

	/**
	 * The read right, mirroring {@see \Bitrix\Tasks\Access\Rule\TemplateReadRule} in SQL:
	 * an individual grant, the department, or the non-department view permission.
	 *
	 * The department branches are matched by `CREATED_BY`, as in {@see AccessFilterModifier} -
	 * the established list-side approximation of the rule, which compares the viewer departments
	 * with the departments of the template members.
	 *
	 * @return ConditionTree|null null - the viewer has no read branch at all
	 */
	private function buildReadFilter(Query $query, AccessChecker $accessChecker): ?ConditionTree
	{
		$filter = Query::filter()->logic(ConditionTree::LOGIC_OR);
		$hasBranch = false;

		if ($accessChecker->hasAccessToDepartmentTemplates())
		{
			$filter->whereIn(Field::CreatedBy->value, $this->getDepartmentMembers());
			$hasBranch = true;
		}

		if ($accessChecker->hasAccessToNonDepartmentTemplates())
		{
			$departmentMembers = $this->getDepartmentMembers();
			$filter->whereNotIn(Field::CreatedBy->value, $departmentMembers === [] ? [0] : $departmentMembers);
			$hasBranch = true;
		}

		$grantFilter = $this->buildGrantFilter($query);
		if ($grantFilter !== null)
		{
			$filter->where($grantFilter);
			$hasBranch = true;
		}

		return $hasBranch ? $filter : null;
	}

	/**
	 * @return ConditionTree|null null - no access code of the viewer can appear among template grants
	 */
	private function buildGrantFilter(Query $query): ?ConditionTree
	{
		$accessCodes = $this->getTemplatePermissionAccessCodes();
		if ($accessCodes === [])
		{
			// Not a single code relevant to template grants (U/SG/D/DR) - there is no individual
			// access. The branch is dropped: an empty set means "no individual access", not an
			// open whereIn over every code of the user.
			return null;
		}

		$this->joinPermissions($query);

		return Query::filter()
			->whereIn('PERMISSIONS.ACCESS_CODE', $accessCodes)
			->whereIn('PERMISSIONS.PERMISSION_ID', [
				PermissionDictionary::TEMPLATE_VIEW,
				PermissionDictionary::TEMPLATE_FULL,
			])
			// a forbidding row is not a grant; TemplateReadRule reads any non-zero value as allowed
			->where('PERMISSIONS.VALUE', '!=', PermissionDictionary::VALUE_NO)
		;
	}

	/**
	 * The grant condition is used by both the ownership and the read branch, while the join
	 * itself must stay single: a second one would multiply the row and shift the pagination.
	 */
	private function joinPermissions(Query $query): void
	{
		if ($this->permissionsJoined)
		{
			return;
		}

		$query->registerRuntimeField(
			(new Reference(
				'PERMISSIONS',
				TasksTemplatePermissionTable::getEntity(),
				['this.ID' => 'ref.TEMPLATE_ID'],
			))->configureJoinType(Join::TYPE_LEFT)
		);

		$this->permissionsJoined = true;
	}

	/**
	 * The viewer access codes narrowed down to the prefixes that can appear in
	 * `b_tasks_template_permission` at all. Template grants are written only for three access
	 * entities - user (U), social network group (SG) and department (D/DR) - exactly the types
	 * {@see \Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\AccessEntityTypeMapper} supports
	 * (TYPE_USER / TYPE_SOCNETGROUP / TYPE_DEPARTMENT) and exactly the prefixes really met in the
	 * table. `getAccessCodes()` returns the whole universe of user codes (hundreds of SG plus
	 * G/IU/AD/AE/SND/SNDR/...), of which only a few are relevant here - the rest only inflate IN(...).
	 *
	 * Perf (M4): the filtering runs before `whereIn('PERMISSIONS.ACCESS_CODE', ...)`, so SQL gets a
	 * short list instead of ~hundreds of codes. The set of visible templates does not change
	 * (checked on the stand: the dropped prefixes do not occur in the table).
	 */
	private function getTemplatePermissionAccessCodes(): array
	{
		static $allowedPrefixes = ['U', 'SG', 'D', 'DR'];

		$codes = $this->getUserModel()->getAccessCodes();

		return array_values(array_filter(
			$codes,
			static function ($code) use ($allowedPrefixes): bool {
				if (!preg_match('/^[A-Z]+/', (string)$code, $matches))
				{
					return false;
				}

				return in_array($matches[0], $allowedPrefixes, true);
			}
		));
	}
}
