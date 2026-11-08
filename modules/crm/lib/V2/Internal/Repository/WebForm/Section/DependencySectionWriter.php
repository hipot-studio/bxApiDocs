<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\DependencyGroup;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\DependencyRule;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormPath;
use Bitrix\Crm\WebForm\Internals\FieldDependenceTable;
use Bitrix\Crm\WebForm\Internals\FieldDepGroupTable;
use Bitrix\Crm\WebForm\Internals\FieldTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Writes the dependency groups and rules of a CRM form, mirroring DependencySectionLoader.
 *
 * Both collections are rewritten as a whole: groups always get fresh rows, so the ids the input
 * carried are replaced by the ids of those rows. A rule is stored only when both of its field
 * codes belong to the field composition of the form.
 *
 * @internal
 */
final class DependencySectionWriter extends WebFormSectionWriter
{
	private const LOGIC_AND = 'and';
	private const LOGIC_OR = 'or';

	public function getSectionName(): string
	{
		return WebForm::SECTION_DEPENDENCIES;
	}

	protected function writeTouched(int $formId, WebFormChangeSet $changeSet): Result
	{
		/** @var DependencyGroup[] $groups */
		$groups = $changeSet->get($this->getSectionName());
		$fieldCodes = self::loadFieldCodes($formId);

		self::deleteFormRows(FieldDependenceTable::class, $formId);
		self::deleteFormRows(FieldDepGroupTable::class, $formId);

		$result = new Result();
		foreach ($groups as $index => $group)
		{
			$rules = self::acceptRules($group, $fieldCodes);
			if ($rules === [])
			{
				// A group without rules is not stored.
				continue;
			}

			$typeId = self::mapLogic($group->logic);
			if ($typeId === null)
			{
				// Unreachable while the domain validation runs first, and addressed to the element
				// of the input all the same: a refusal names the place the value came from.
				return $result->addError(new Error(
					sprintf('Unknown web form dependency logic `%s`', $group->logic),
					WebFormPath::property(
						WebFormPath::item($this->getSectionName(), $index),
						DependencyGroup::FIELD_LOGIC,
					),
				));
			}

			$groupResult = FieldDepGroupTable::add([
				'FORM_ID' => $formId,
				'TYPE_ID' => $typeId,
			]);
			if (!self::copyErrors($groupResult, $result))
			{
				return $result;
			}

			// The fresh row id is what the rules of this group point at: nesting the rules inside
			// their group replaces the legacy map of temporary group ids.
			$groupId = (int)$groupResult->getId();
			// The rules of a group go in one statement. The contract lets a document carry hundreds
			// of groups of hundreds of rules, and a statement per rule would spend the request on
			// round trips alone; the table declares no add handler, so nothing depends on the rows
			// arriving one by one. The group rows stay separate: each of them answers with the id its
			// own rules point at.
			$rows = [];
			foreach ($rules as $rule)
			{
				$rows[] = self::createRow($rule, $formId, $groupId);
			}

			if (!self::copyErrors(FieldDependenceTable::addMulti($rows), $result))
			{
				return $result;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, true> $fieldCodes
	 * @return DependencyRule[]
	 */
	private static function acceptRules(DependencyGroup $group, array $fieldCodes): array
	{
		$rules = [];
		foreach ($group->rules ?? [] as $rule)
		{
			$conditionCode = (string)($rule->condition?->fieldName ?? '');
			$actionCode = (string)($rule->action?->fieldName ?? '');
			if (isset($fieldCodes[$conditionCode], $fieldCodes[$actionCode]))
			{
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function createRow(DependencyRule $rule, int $formId, int $groupId): array
	{
		return [
			'FORM_ID' => $formId,
			'GROUP_ID' => $groupId,
			'IF_FIELD_CODE' => (string)$rule->condition?->fieldName,
			'IF_ACTION' => $rule->condition?->event,
			'IF_VALUE' => $rule->condition?->value,
			'IF_VALUE_OPERATION' => $rule->condition?->operation,
			'DO_FIELD_CODE' => (string)$rule->action?->fieldName,
			'DO_ACTION' => $rule->action?->type,
			'DO_VALUE' => $rule->action?->value,
		];
	}

	/**
	 * Field codes stored by the form. Legacy reads them back after the fields are written, so that
	 * a rule referring to a field the same write removed is left out.
	 *
	 * @return array<string, true>
	 */
	private static function loadFieldCodes(int $formId): array
	{
		$rows = FieldTable::query()
			->setSelect(['CODE'])
			->where('FORM_ID', $formId)
			->fetchAll()
		;

		$codes = [];
		foreach ($rows as $row)
		{
			$codes[(string)$row['CODE']] = true;
		}

		return $codes;
	}

	/**
	 * Mirrors the mapping of DependencySectionLoader, which reads every type but AND as `or`.
	 * An unspecified logic takes the default of the table. Null tells an unknown logic apart.
	 */
	private static function mapLogic(?string $logic): ?int
	{
		return match ($logic)
		{
			null => FieldDepGroupTable::TYPE_DEF,
			self::LOGIC_AND => FieldDepGroupTable::TYPE_AND,
			self::LOGIC_OR => FieldDepGroupTable::TYPE_OR,
			default => null,
		};
	}
}
