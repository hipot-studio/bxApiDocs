<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Activity\Param;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

/**
 * What {@see \Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider::getList()} narrows the activities by: an
 * optional CRM object the activity must be bound to, joined by AND to conditions over the fields of an activity.
 *
 * The conditions name the fields by their names in the model of an activity and hold its values: enums, dates,
 * identifiers. The fields a list may be narrowed by are a closed list; a condition outside it is refused by the
 * provider.
 */
final class ActivityListFilter
{
	private readonly ConditionTree $conditions;

	/**
	 * @throws ArgumentException a binding given by half, or not by positive identifiers.
	 */
	public function __construct(
		private readonly ?int $bindingEntityTypeId = null,
		private readonly ?int $bindingEntityId = null,
		?ConditionTree $conditions = null,
	)
	{
		if (($bindingEntityTypeId === null) !== ($bindingEntityId === null))
		{
			throw new ArgumentException('A binding needs both the type and the id of the object.', 'binding');
		}
		if ($bindingEntityTypeId !== null && ($bindingEntityTypeId <= 0 || $bindingEntityId <= 0))
		{
			throw new ArgumentException('A binding is named by positive identifiers.', 'binding');
		}

		$this->conditions = $conditions !== null ? clone $conditions : new ConditionTree();
	}

	public function hasBinding(): bool
	{
		return $this->bindingEntityTypeId !== null;
	}

	public function getBindingEntityTypeId(): ?int
	{
		return $this->bindingEntityTypeId;
	}

	public function getBindingEntityId(): ?int
	{
		return $this->bindingEntityId;
	}

	public function getConditions(): ConditionTree
	{
		return clone $this->conditions;
	}
}
