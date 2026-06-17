<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Tasks\Access\Permission\PermissionDictionary;
use Bitrix\Tasks\Access\Permission\TasksTemplatePermissionTable;
use Bitrix\Tasks\Provider\UserProviderTrait;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\AccessChecker;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

class AccessFilterModifier extends BaseFilterModifier
{
	use UserProviderTrait;

	private AccessChecker $accessChecker;

	public function __construct(Field $field, string $operator, mixed $value)
	{
		parent::__construct($field, $operator, $value);
		$this->accessChecker = new AccessChecker((int)$this->value);
		$this->executorId = (int)$this->value;
	}

	public function modify(Query $query): Query
	{
		$accessFilter = Query::filter()
			->logic(ConditionTree::LOGIC_OR);

		if ($this->accessChecker->hasAccessToDepartmentTemplates())
		{
			$accessFilter = $this->filterDepartmentTemplates($accessFilter);
		}

		if ($this->accessChecker->hasAccessToNonDepartmentTemplates())
		{
			$accessFilter = $this->filterNonDepartmentTemplates($accessFilter);
		}

		if ($this->accessChecker->hasAccessByIndividualRights())
		{
			$query->registerRuntimeField(
				(new Reference(
					'PERMISSIONS',
					TasksTemplatePermissionTable::getEntity(),
					['this.ID' => 'ref.TEMPLATE_ID'],
				))->configureJoinType(Join::TYPE_LEFT)
			);

			$accessFilter = $this->filterByIndividualRights($accessFilter);
		}

		if (!$this->accessChecker->hasAccess())
		{
			// FALSE условие - если доступа нет, то выборка должна быть пустой
			$query->whereNull('ID');
		}

		return $query->where($accessFilter);
	}

	protected function filterDepartmentTemplates(ConditionTree $filter): ConditionTree
	{
		$departmentMembers = $this->getDepartmentMembers();
		return $filter->whereIn(Field::CreatedBy->value, $departmentMembers);
	}

	protected function filterNonDepartmentTemplates(ConditionTree $filter): ConditionTree
	{
		$departmentMembers = $this->getDepartmentMembers();
		return $filter->whereNotIn(Field::CreatedBy->value, empty($departmentMembers) ? [0] : $departmentMembers);
	}

	protected function filterByIndividualRights(ConditionTree $filter): ConditionTree
	{
		$accessCodes = $this->getUserModel()->getAccessCodes();

		$individualRightsFilter = Query::filter()
			->whereIn('PERMISSIONS.ACCESS_CODE', $accessCodes)
			->whereIn('PERMISSIONS.PERMISSION_ID', [
				PermissionDictionary::TEMPLATE_VIEW,
				PermissionDictionary::TEMPLATE_FULL,
			])
		;

		return $filter->where($individualRightsFilter);
	}
}
