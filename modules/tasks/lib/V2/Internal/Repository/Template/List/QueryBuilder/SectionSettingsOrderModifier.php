<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\WorkgroupTable;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

final class SectionSettingsOrderModifier implements QueryModifierInterface
{
	private const PROJECT_REFERENCE = 'SECTION_PROJECT';
	private const PROJECT_EMPTY = 'SECTION_PROJECT_EMPTY';
	private const ASSIGNEE_EMPTY = 'SECTION_ASSIGNEE_EMPTY';
	private const TYPE_RANK = 'SECTION_TYPE_RANK';
	private const DEADLINE_EMPTY = 'SECTION_DEADLINE_EMPTY';
	private const ORIGINATOR_EMPTY = 'SECTION_ORIGINATOR_EMPTY';

	public function __construct(
		private readonly Field $field,
		private readonly string $direction,
	)
	{
	}

	public function modify(Query $query): Query
	{
		return match ($this->field)
		{
			Field::SectionProjectOrder => $this->applyProjectOrder($query),
			Field::SectionAssigneeOrder => $this->applyUserOrder($query, 'RESPONSIBLE', self::ASSIGNEE_EMPTY),
			Field::SectionTypeOrder => $this->applyTypeOrder($query),
			Field::SectionNameOrder => $this->applySimpleOrder($query, Field::Title),
			Field::SectionDeadlineOrder => $this->applyDeadlineOrder($query),
			Field::SectionOriginatorOrder => $this->applyUserOrder($query, 'CREATOR', self::ORIGINATOR_EMPTY),
			Field::SectionPriorityOrder => $this->applySimpleOrder($query, Field::Priority),
			default => $query,
		};
	}

	private function applyProjectOrder(Query $query): Query
	{
		$query->registerRuntimeField(
			(new Reference(
				self::PROJECT_REFERENCE,
				WorkgroupTable::getEntity(),
				Join::on('this.GROUP_ID', 'ref.ID'),
			))->configureJoinType(Join::TYPE_LEFT),
		);

		$this->addNullLastOrder(
			$query,
			self::PROJECT_REFERENCE . '.ID',
			self::PROJECT_EMPTY,
		);

		return $query
			->addSelect(self::PROJECT_REFERENCE . '.NAME', 'SECTION_PROJECT_NAME')
			->addSelect(Field::GroupId->value)
			->addOrder(self::PROJECT_REFERENCE . '.NAME', $this->direction)
			->addOrder(Field::GroupId->value, $this->direction)
		;
	}

	private function applyUserOrder(Query $query, string $reference, string $emptyField): Query
	{
		$this->addNullLastOrder($query, $reference . '.ID', $emptyField);

		foreach (['LAST_NAME', 'NAME', 'SECOND_NAME', 'LOGIN'] as $field)
		{
			$definition = $reference . '.' . $field;
			$query
				->addSelect($definition)
				->addOrder($definition, $this->direction)
			;
		}

		$idField = $reference === 'RESPONSIBLE' ? Field::ResponsibleId : Field::CreatedBy;

		return $query
			->addSelect($idField->value)
			->addOrder($idField->value, $this->direction)
		;
	}

	private function applyTypeOrder(Query $query): Query
	{
		$expression = sprintf(
			'CASE COALESCE(%%s, %d) WHEN %d THEN 0 WHEN %d THEN 1 WHEN %d THEN 2 ELSE 3 END',
			RegularityType::Schedule->value,
			RegularityType::Schedule->value,
			RegularityType::Deadline->value,
			RegularityType::AfterCompletion->value,
		);

		$query->registerRuntimeField(
			new ExpressionField(
				self::TYPE_RANK,
				$expression,
				[Field::ReplicateType->value],
			),
		);

		return $query
			->addSelect(self::TYPE_RANK)
			->addOrder(self::TYPE_RANK, $this->direction)
		;
	}

	private function applyDeadlineOrder(Query $query): Query
	{
		$query->registerRuntimeField(
			new ExpressionField(
				self::DEADLINE_EMPTY,
				'CASE WHEN %s IS NULL OR %s <= 0 THEN 1 ELSE 0 END',
				[
					Field::Deadline->value,
					Field::Deadline->value,
				],
			),
		);

		return $query
			->addSelect(self::DEADLINE_EMPTY)
			->addSelect(Field::Deadline->value)
			->addOrder(self::DEADLINE_EMPTY, 'ASC')
			->addOrder(Field::Deadline->value, $this->direction)
		;
	}

	private function applySimpleOrder(Query $query, Field $field): Query
	{
		return $query
			->addSelect($field->value)
			->addOrder($field->value, $this->direction)
		;
	}

	private function addNullLastOrder(Query $query, string $definition, string $emptyField): void
	{
		$query->registerRuntimeField(
			new ExpressionField(
				$emptyField,
				'CASE WHEN %s IS NULL THEN 1 ELSE 0 END',
				[$definition],
			),
		);
		$query
			->addSelect($emptyField)
			->addOrder($emptyField, 'ASC')
		;
	}
}
