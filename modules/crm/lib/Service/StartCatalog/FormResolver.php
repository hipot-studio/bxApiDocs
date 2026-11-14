<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\WebForm\Entity;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Crm\WebForm\Internals\ResultTable;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;

/**
 * The living CRM forms of smart processes and the one bound in the settings. A living form uses a
 * supported scheme with the smart process as its main entity, is active, is not a system one and
 * does not check smart process items for duplicates.
 */
class FormResolver
{
	private const LAST_SUBMISSION_FIELD = 'LAST_SUBMISSION_DATE';
	private const DUPLICATE_CONTROL_SETTING = 'DYNAMIC_DC_ENABLED';
	private const CATEGORY_SETTING = 'DYNAMIC_CATEGORY';

	/**
	 * @return list<array{id: int, name: string, code: string, securityCode: string, active: bool, categoryId: int}>
	 */
	public function getFormsForType(int $entityTypeId): array
	{
		return $this->getLiveForms([$entityTypeId])[$entityTypeId] ?? [];
	}

	/**
	 * @param int[] $entityTypeIds
	 * @return array<int, list<array{
	 *     id: int,
	 *     name: string,
	 *     code: string,
	 *     securityCode: string,
	 *     active: bool,
	 *     categoryId: int,
	 * }>>
	 */
	public function getLiveForms(array $entityTypeIds): array
	{
		$entityTypeIds = $this->prepareIds($entityTypeIds);
		$schemeEntityTypeIds = $this->getSchemeEntityTypeIds($entityTypeIds);

		$forms = array_fill_keys($entityTypeIds, []);
		$filter = [
			'@ENTITY_SCHEME' => array_keys($schemeEntityTypeIds),
			'=ACTIVE' => 'Y',
			'=IS_SYSTEM' => 'N',
		];
		foreach (($schemeEntityTypeIds ? $this->loadForms($filter) : []) as $row)
		{
			$entityTypeId = $this->getLiveEntityTypeId($row, $schemeEntityTypeIds);
			if ($entityTypeId !== null && isset($forms[$entityTypeId]))
			{
				$forms[$entityTypeId][] = $this->prepareForm($row);
			}
		}

		return $forms;
	}

	/**
	 * The chosen form can be deleted, switched off, re-pointed at another smart process or made to merge
	 * duplicates after it was chosen, so the setting alone does not answer whether there is a form to open.
	 *
	 * @param int[] $entityTypeIds
	 * @return array<int, array{
	 *     state: BindingState,
	 *     form: array{id: int, name: string, code: string, securityCode: string, active: bool, categoryId: int}|null,
	 * }>
	 */
	public function getBindings(array $entityTypeIds): array
	{
		$entityTypeIds = $this->prepareIds($entityTypeIds);
		$schemeEntityTypeIds = $this->getSchemeEntityTypeIds($entityTypeIds);

		$boundFormIds = [];
		foreach ($this->getTypes($entityTypeIds) as $entityTypeId => $type)
		{
			$formId = (int)$type->getStartCatalogFormId();
			if ($formId > 0)
			{
				$boundFormIds[$entityTypeId] = $formId;
			}
		}

		$rows = [];
		$filter = ['@ID' => array_values(array_unique($boundFormIds))];
		foreach (($boundFormIds ? $this->loadForms($filter) : []) as $row)
		{
			$rows[(int)$row['ID']] = $row;
		}

		$bindings = [];
		foreach ($entityTypeIds as $entityTypeId)
		{
			$formId = $boundFormIds[$entityTypeId] ?? 0;
			$row = $rows[$formId] ?? null;

			$bindings[$entityTypeId] = [
				'state' => $this->getBindingState($entityTypeId, $formId, $row, $schemeEntityTypeIds),
				'form' => $row === null ? null : $this->prepareForm($row),
			];
		}

		return $bindings;
	}

	/**
	 * @return array{id: int, name: string, code: string, securityCode: string, active: bool, categoryId: int}|null
	 */
	public function getBoundForm(int $entityTypeId): ?array
	{
		$binding = $this->getBindings([$entityTypeId])[$entityTypeId] ?? null;

		return ($binding !== null && $binding['state'] === BindingState::Live) ? $binding['form'] : null;
	}

	public function isFormBound(int $entityTypeId): bool
	{
		$type = $this->getTypes([$entityTypeId])[$entityTypeId] ?? null;

		return (int)$type?->getStartCatalogFormId() > 0;
	}

	/**
	 * The whole catalog is answered by one request over the index of form and date; a form nobody
	 * has submitted is absent from the answer.
	 *
	 * @param int[] $formIds
	 * @return array<int, DateTime>
	 */
	public function getLastSubmissionDates(array $formIds): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $formIds))));
		if (!$ids)
		{
			return [];
		}

		// the map of the table calls DATE_INSERT a date and a plain select of it comes back without
		// the time of the day, so the aggregate states its own type instead of inheriting that one
		$lastSubmission = (new ExpressionField(self::LAST_SUBMISSION_FIELD, 'MAX(%s)', ['DATE_INSERT']))
			->configureValueType(DatetimeField::class)
		;

		$rows = ResultTable::query()
			->registerRuntimeField($lastSubmission)
			->setSelect(['FORM_ID', self::LAST_SUBMISSION_FIELD])
			->whereIn('FORM_ID', $ids)
			->setGroup(['FORM_ID'])
			->fetchAll()
		;

		$dates = [];
		foreach ($rows as $row)
		{
			if ($row[self::LAST_SUBMISSION_FIELD] instanceof DateTime)
			{
				$dates[(int)$row['FORM_ID']] = $row[self::LAST_SUBMISSION_FIELD];
			}
		}

		return $dates;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function loadForms(array $filter): array
	{
		return FormTable::getDefaultTypeList([
			'select' => [
				'ID',
				'NAME',
				'CODE',
				'SECURITY_CODE',
				'ACTIVE',
				'IS_SYSTEM',
				'ENTITY_SCHEME',
				'FORM_SETTINGS',
			],
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
		])->fetchAll();
	}

	private function getBindingState(
		int $entityTypeId,
		int $formId,
		?array $row,
		array $schemeEntityTypeIds,
	): BindingState
	{
		if ($formId <= 0)
		{
			return BindingState::None;
		}

		if ($row === null)
		{
			return BindingState::Deleted;
		}

		if (!$this->isYes($row['ACTIVE']))
		{
			return BindingState::Inactive;
		}

		return $this->getLiveEntityTypeId($row, $schemeEntityTypeIds) === $entityTypeId
			? BindingState::Live
			: BindingState::Unsuitable;
	}

	/**
	 * The smart process the form is a living form of, if it is one at all.
	 */
	private function getLiveEntityTypeId(array $row, array $schemeEntityTypeIds): ?int
	{
		$scheme = (int)$row['ENTITY_SCHEME'];
		$entityTypeId = $schemeEntityTypeIds[$scheme] ?? null;
		$settings = is_array($row['FORM_SETTINGS'] ?? null) ? $row['FORM_SETTINGS'] : [];

		if (
			$entityTypeId === null
			|| !$this->isYes($row['ACTIVE'])
			|| $this->isYes($row['IS_SYSTEM'])
			|| ($settings[self::DUPLICATE_CONTROL_SETTING] ?? null) === 'Y'
		)
		{
			return null;
		}

		return $entityTypeId;
	}

	/**
	 * @param int[] $entityTypeIds
	 * @return array<int, int>
	 */
	private function getSchemeEntityTypeIds(array $entityTypeIds): array
	{
		if (!$entityTypeIds)
		{
			return [];
		}

		$wantedEntityTypeIds = array_fill_keys($entityTypeIds, true);
		$schemeEntityTypeIds = [];
		foreach (Entity::getSchemes() as $schemeId => $scheme)
		{
			$mainEntityTypeId = $scheme['MAIN_ENTITY'] ?? null;
			if (
				!is_int($mainEntityTypeId)
				|| !isset($wantedEntityTypeIds[$mainEntityTypeId])
				|| ($scheme['DYNAMIC'] ?? false) !== true
				|| !Entity::isSchemeSupportEntity((int)$schemeId, $mainEntityTypeId)
			)
			{
				continue;
			}

			$schemeEntityTypeIds[(int)$schemeId] = $mainEntityTypeId;
		}

		return $schemeEntityTypeIds;
	}

	/**
	 * @param int[] $entityTypeIds
	 * @return array<int, Type>
	 */
	private function getTypes(array $entityTypeIds): array
	{
		$wanted = array_flip($entityTypeIds);

		$types = [];
		// a snapshot: the iterator of the collection is shared, and the caller may be walking it too
		foreach (Container::getInstance()->getDynamicTypesMap()->getTypesCollection()->getAll() as $type)
		{
			if (isset($wanted[$type->getEntityTypeId()]))
			{
				$types[$type->getEntityTypeId()] = $type;
			}
		}

		return $types;
	}

	/**
	 * @param array<int|string> $ids
	 * @return int[]
	 */
	private function prepareIds(array $ids): array
	{
		return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
	}

	private function isYes(mixed $value): bool
	{
		return $value === true || $value === 'Y';
	}

	/**
	 * @return array{id: int, name: string, code: string, securityCode: string, active: bool, categoryId: int}
	 */
	private function prepareForm(array $row): array
	{
		$settings = is_array($row['FORM_SETTINGS'] ?? null) ? $row['FORM_SETTINGS'] : [];

		return [
			'id' => (int)$row['ID'],
			'name' => (string)$row['NAME'],
			'code' => (string)$row['CODE'],
			'securityCode' => (string)$row['SECURITY_CODE'],
			'active' => $this->isYes($row['ACTIVE']),
			'categoryId' => (int)($settings[self::CATEGORY_SETTING] ?? 0),
		];
	}
}
