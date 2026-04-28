<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Type\Query;

use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

class TypeFilter
{
	public function __construct(
		private readonly TypeCondition $condition,
		private readonly string $typeField = 'ITEM_TYPE',
		private readonly string $entityTypeField = 'CHAT.ENTITY_TYPE',
	) {}

	public function toConditionTree(): ConditionTree
	{
		$result = new ConditionTree();

		if ($this->condition->include !== null)
		{
			if (empty($this->condition->include))
			{
				$result->where(new ExpressionField('ALWAYS_FALSE', '0'), '=', '1');

				return $result;
			}

			$includeCondition = Query::filter()->logic('OR');
			foreach ($this->condition->include as $type)
			{
				$sub = Query::filter()->where($this->typeField, $type->literal);
				if ($type->entityType !== null)
				{
					$sub->where($this->entityTypeField, $type->entityType);
				}
				$includeCondition->where($sub);
			}
			$result->where($includeCondition);
		}

		foreach ($this->condition->exclude as $type)
		{
			if ($type->entityType !== null)
			{
				$result->where(
					Query::filter()->logic('OR')
						->whereNot($this->typeField, $type->literal)
						->whereNull($this->entityTypeField)
						->whereNot($this->entityTypeField, $type->entityType)
				);
			}
			else
			{
				$result->whereNot($this->typeField, $type->literal);
			}
		}

		return $result;
	}
}
