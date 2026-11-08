<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\DependencyAction;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\DependencyCondition;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\DependencyGroup;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\DependencyRule;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\WebForm\Internals\FieldDependenceTable;
use Bitrix\Crm\WebForm\Internals\FieldDepGroupTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * @internal
 */
final class DependencySectionLoader extends WebFormSectionLoader
{
	private const GROUP_REFERENCE = 'DEPENDENCY_GROUP';
	private const GROUP_TYPE_COLUMN = 'GROUP_TYPE_ID';

	private const RULES_PATH = DependencyGroup::FIELD_RULES;
	private const CONDITION_PATH = self::RULES_PATH . '.' . DependencyRule::FIELD_CONDITION;
	private const ACTION_PATH = self::RULES_PATH . '.' . DependencyRule::FIELD_ACTION;

	private const COLUMN_BY_PATH = [
		self::CONDITION_PATH . '.' . DependencyCondition::FIELD_FIELD_NAME => 'IF_FIELD_CODE',
		self::CONDITION_PATH . '.' . DependencyCondition::FIELD_EVENT => 'IF_ACTION',
		self::CONDITION_PATH . '.' . DependencyCondition::FIELD_VALUE => 'IF_VALUE',
		self::CONDITION_PATH . '.' . DependencyCondition::FIELD_OPERATION => 'IF_VALUE_OPERATION',
		self::ACTION_PATH . '.' . DependencyAction::FIELD_FIELD_NAME => 'DO_FIELD_CODE',
		self::ACTION_PATH . '.' . DependencyAction::FIELD_TYPE => 'DO_ACTION',
		self::ACTION_PATH . '.' . DependencyAction::FIELD_VALUE => 'DO_VALUE',
	];

	public function getSectionName(): string
	{
		return WebForm::SECTION_DEPENDENCIES;
	}

	/**
	 * @param int[] $formIds
	 * @param string[] $selectedFields
	 * @return array<int, DependencyGroup[]>
	 */
	protected function loadSelected(array $formIds, array $selectedFields): array
	{
		$selectedFieldMap = array_fill_keys($selectedFields, true);
		$columns = ['FORM_ID', 'GROUP_ID', 'ID'];
		if (isset($selectedFieldMap[DependencyGroup::FIELD_LOGIC]))
		{
			$columns[self::GROUP_TYPE_COLUMN] = self::GROUP_REFERENCE . '.TYPE_ID';
		}

		foreach (self::COLUMN_BY_PATH as $path => $column)
		{
			if (self::isLeafSelected($selectedFieldMap, $path))
			{
				$columns[] = $column;
			}
		}

		$query =
			FieldDependenceTable::query()
				->registerRuntimeField(
					new Reference(
						self::GROUP_REFERENCE,
						FieldDepGroupTable::class,
						Join::on('this.GROUP_ID', 'ref.ID')
							->whereColumn('this.FORM_ID', 'ref.FORM_ID'),
						['join_type' => Join::TYPE_LEFT],
					),
				)
				->setSelect(array_unique($columns))
				->whereIn('FORM_ID', $formIds)
				->setOrder([
					'FORM_ID' => 'ASC',
					'GROUP_ID' => 'ASC',
					'ID' => 'ASC',
				])
		;

		$groupsByFormId = [];
		$groupIndexByFormId = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$formId = (int)$row['FORM_ID'];
			$groupId = (int)$row['GROUP_ID'];
			if (!isset($groupIndexByFormId[$formId][$groupId]))
			{
				$groupIndexByFormId[$formId][$groupId] = count($groupsByFormId[$formId] ?? []);
				$groupsByFormId[$formId][] = [
					'logic' => isset($selectedFieldMap[DependencyGroup::FIELD_LOGIC])
						? self::mapLogic($row[self::GROUP_TYPE_COLUMN] ?? null)
						: null,
					'rules' => self::hasSelectedPath($selectedFieldMap, self::RULES_PATH) ? [] : null,
				];
			}

			if (!self::hasSelectedPath($selectedFieldMap, self::RULES_PATH))
			{
				continue;
			}

			$groupIndex = $groupIndexByFormId[$formId][$groupId];
			$groupsByFormId[$formId][$groupIndex]['rules'][] = self::createRule($row, $selectedFieldMap);
		}

		return self::createGroups($groupsByFormId);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function createRule(array $row, array $selectedFields): DependencyRule
	{
		$conditionSelected =
			isset($selectedFields[self::RULES_PATH])
			|| self::hasSelectedPath($selectedFields, self::CONDITION_PATH)
		;
		$actionSelected =
			isset($selectedFields[self::RULES_PATH])
			|| self::hasSelectedPath($selectedFields, self::ACTION_PATH)
		;

		return new DependencyRule(
			condition: $conditionSelected ? self::createCondition($row, $selectedFields) : null,
			action: $actionSelected ? self::createAction($row, $selectedFields) : null,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function createCondition(array $row, array $selectedFields): DependencyCondition
	{
		return new DependencyCondition(
			fieldName: self::requiredString(
				$row,
				$selectedFields,
				self::CONDITION_PATH . '.' . DependencyCondition::FIELD_FIELD_NAME,
			),
			event: self::requiredString(
				$row,
				$selectedFields,
				self::CONDITION_PATH . '.' . DependencyCondition::FIELD_EVENT,
			),
			value: self::nullableString(
				$row,
				$selectedFields,
				self::CONDITION_PATH . '.' . DependencyCondition::FIELD_VALUE,
			),
			operation: self::nullableString(
				$row,
				$selectedFields,
				self::CONDITION_PATH . '.' . DependencyCondition::FIELD_OPERATION,
			),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function createAction(array $row, array $selectedFields): DependencyAction
	{
		return new DependencyAction(
			fieldName: self::requiredString(
				$row,
				$selectedFields,
				self::ACTION_PATH . '.' . DependencyAction::FIELD_FIELD_NAME,
			),
			type: self::requiredString(
				$row,
				$selectedFields,
				self::ACTION_PATH . '.' . DependencyAction::FIELD_TYPE,
			),
			value: self::nullableString(
				$row,
				$selectedFields,
				self::ACTION_PATH . '.' . DependencyAction::FIELD_VALUE,
			),
		);
	}

	/**
	 * @param array<int, array<int, array{logic: string|null, rules: array|null}>> $groupsByFormId
	 * @return array<int, DependencyGroup[]>
	 */
	private static function createGroups(array $groupsByFormId): array
	{
		$result = [];
		foreach ($groupsByFormId as $formId => $groups)
		{
			foreach ($groups as $group)
			{
				$result[$formId][] = new DependencyGroup(
					logic: $group['logic'],
					rules: $group['rules'],
				);
			}
		}

		return $result;
	}

	private static function mapLogic(mixed $typeId): string
	{
		return match ((int)($typeId ?? FieldDepGroupTable::TYPE_DEF))
		{
			FieldDepGroupTable::TYPE_AND => 'and',
			default => 'or',
		};
	}

	/**
	 * @param array<string, true> $selectedFields
	 */
	private static function hasSelectedPath(array $selectedFields, string $path): bool
	{
		if (isset($selectedFields[$path]))
		{
			return true;
		}

		$prefix = $path . '.';
		foreach ($selectedFields as $selectedPath => $_)
		{
			if (str_starts_with($selectedPath, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, true> $selectedFields
	 */
	private static function isLeafSelected(array $selectedFields, string $path): bool
	{
		while (true)
		{
			if (isset($selectedFields[$path]))
			{
				return true;
			}

			$separatorPosition = strrpos($path, '.');
			if ($separatorPosition === false)
			{
				return false;
			}

			$path = substr($path, 0, $separatorPosition);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function requiredString(array $row, array $selectedFields, string $path): ?string
	{
		if (!self::isLeafSelected($selectedFields, $path))
		{
			return null;
		}

		return (string)($row[self::COLUMN_BY_PATH[$path]] ?? '');
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function nullableString(array $row, array $selectedFields, string $path): ?string
	{
		if (!self::isLeafSelected($selectedFields, $path))
		{
			return null;
		}

		$value = $row[self::COLUMN_BY_PATH[$path]] ?? null;

		return $value === null ? null : (string)$value;
	}
}
