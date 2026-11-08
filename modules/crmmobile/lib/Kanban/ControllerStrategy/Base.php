<?php

namespace Bitrix\CrmMobile\Kanban\ControllerStrategy;

use Bitrix\Crm\Field\Collection;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Display\Field;
use Bitrix\Crm\Service\Factory;
use Bitrix\CrmMobile\Kanban\View\CompactViewResolver;
use Bitrix\CrmMobile\Kanban\View\DisplayFieldFactory;
use Bitrix\Crm\Filter;
use Bitrix\Crm\Filter\EntitySettings;
use Bitrix\Crm\Kanban\Entity;
use Bitrix\Crm\Kanban\EntityActivityCounter;
use Bitrix\Crm\Security\Manager;
use Bitrix\CrmMobile\Public\Feature\UnifiedFiltersFlag;
use Bitrix\CrmMobile\Kanban\Kanban;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Result;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Mobile\Filter\AppliedFilterPointer;
use Bitrix\Mobile\Filter\FilterRequest;

abstract class Base implements StrategyInterface
{
	protected const TMP_FILTER_PRESET_ID = FilterRequest::TMP_PRESET_ID;

	protected array $params;
	protected int $entityTypeId;
	protected array $factories = [];

	public function setParams(array $params): Base
	{
		$this->params = $params;

		return $this;
	}

	public function setEntityTypeId(int $entityTypeId): Base
	{
		$this->entityTypeId = $entityTypeId;

		return $this;
	}

	protected function getFilterParams(): array
	{
		return ($this->params['filterParams'] ?? []);
	}

	/**
	 * Conditions the request carries on its own, next to the preset: the search string every list
	 * shares and the funnel of this one. A funnel is how CRM names a list, so it is added here and
	 * not in the protocol of the request shared with the other modules.
	 */
	protected function getBaseFilter(): array
	{
		$filter = FilterRequest::buildSearchFilter($this->params);

		$categoryId = ($this->getFilterParams()['CATEGORY_ID'] ?? null);
		if ($categoryId !== null)
		{
			$filter['CATEGORY_ID'] = (int)$categoryId;
		}

		return $filter;
	}

	protected function usesStoredFilter(string $presetId): bool
	{
		return FilterRequest::usesStoredFilter($presetId, Feature::isEnabled(UnifiedFiltersFlag::class));
	}

	protected function setFilterPreset(string $presetId, Options $filterOptions): void
	{
		$presetId = FilterRequest::resolvePresetId($presetId);

		$presets = $filterOptions->getPresets();

		if ($presetId !== self::TMP_FILTER_PRESET_ID && !empty($presets[$presetId]))
		{
			$data = FilterRequest::buildSavedPresetSettings($presetId, $presets[$presetId]);
		}
		elseif ($presetId === self::TMP_FILTER_PRESET_ID)
		{
			$data = FilterRequest::buildTmpPresetSettings($this->getCurrentFilter());
		}
		else
		{
			return;
		}

		// the list of this very request is read under these conditions (ListStrategy::getFilter()), so
		// they go into the options it was built from as well - that instance is never saved
		$filterOptions->setFilterSettings($presetId, $data);

		$storage = $this->openStorageForWrite($filterOptions);
		// the client named a filter, so the pointer of the list moves to it. The same writer serves the
		// lists of tasks and projects: it says the move explicitly instead of leaving it to
		// `apply_filter` in the request - the protocol of the web form and not of this one - and it
		// leaves the storage alone when it already holds exactly this
		(new AppliedFilterPointer($storage))
			->rememberSettings($presetId, $this->buildSettingsToStore($storage, $presetId, $data))
		;
	}

	/**
	 * What the pointer of the list carries into the storage of the user.
	 *
	 * The conditions this request reads the list under are not the preset the user saved: a tariff
	 * restriction has taken the closed columns out of its rows, and `removeValuesWithoutRows()` has
	 * dropped the values that lost their row. Writing that back would keep a restriction in the storage
	 * as if the user had decided it - the web list stops filtering by such a value too, and raising the
	 * tariff does not bring it back.
	 *
	 * So a saved preset travels as the storage itself holds it. The temporary preset has no copy there
	 * to travel as: it is the form of this very request, and the values in it are what the user has just
	 * chosen.
	 */
	protected function buildSettingsToStore(Options $storage, string $presetId, array $data): array
	{
		if ($presetId === self::TMP_FILTER_PRESET_ID)
		{
			return $data;
		}

		$stored = ($storage->getPresets()[$presetId] ?? null);

		return is_array($stored) ? FilterRequest::buildSavedPresetSettings($presetId, $stored) : $data;
	}

	/**
	 * Storage the pointer of the list is written into, read at the moment of the write.
	 *
	 * `Options::save()` writes the whole storage of the list back - every preset of the user with it -
	 * while the options the list was built from are a snapshot taken before the items were read. A
	 * filter saved from the drawer in between is in the storage and not in that snapshot, so saving the
	 * snapshot deletes it: the request that reads the list carries away the filter the user has just
	 * created. The values the list is read under stay on the options of the list and are not written
	 * anywhere - a tariff restriction is not a decision of the user to be kept in their storage.
	 */
	protected function openStorageForWrite(Options $filterOptions): Options
	{
		return new Options($filterOptions->getId(), $filterOptions->getDefaultPresets());
	}

	/**
	 * Conditions the list is read under when the request names no preset: the pointer of the storage
	 * shared with the web already names them, and there is nothing to write.
	 *
	 * The conditions the request carries on its own win over the stored ones: the search string is
	 * typed over whatever filter the list is under, and the funnel is the list itself.
	 *
	 * @param Options $filterOptions options of the list, as it is read from
	 * @param array $filter conditions the request carries on its own (getBaseFilter)
	 * @return array
	 */
	protected function readStoredFilter(Options $filterOptions, array $filter): array
	{
		// the pointer may name a preset a restriction has taken a column out of, and this branch reaches
		// getFilter() without passing setFilterPreset() - the one place that used to drop the values such
		// a column left behind
		$this->removeValuesWithoutRows($filterOptions, (string)$filterOptions->getCurrentFilterId());

		return array_merge($filterOptions->getFilter(), $filter);
	}

	/**
	 * Values of a preset the rows of its form no longer hold. A tariff restriction closes a column, and
	 * the FieldRestrictionManager takes it out of `filter_rows` while `fields` keeps its value: such a
	 * value goes on narrowing the list while no form of either client shows it.
	 *
	 * Written into the options and not saved: the request only reads the list under this filter, and a
	 * restriction is not a decision of the user to be kept in their storage.
	 */
	protected function removeValuesWithoutRows(Options $filterOptions, string $presetId): void
	{
		if ($presetId === '')
		{
			return;
		}

		$filterSettings = $filterOptions->getFilterSettings($presetId);

		if (
			!isset($filterSettings['fields'], $filterSettings['filter_rows'])
			|| !is_array($filterSettings['fields'])
			|| !is_string($filterSettings['filter_rows'])
		)
		{
			return;
		}

		$fields = $filterSettings['fields'];
		$allowedFields = FilterRequest::filterAllowedFields($fields, $filterSettings['filter_rows']);

		if (count($allowedFields) < count($fields))
		{
			$filterOptions->setFilterSettings($presetId, ['fields' => $allowedFields], false, false);
		}
	}

	public function getEntityType(): string
	{
		return \CCrmOwnerType::ResolveName($this->entityTypeId);
	}

	protected function prepareActivityCounters(array &$items): void
	{
		if (empty($items))
		{
			return;
		}

		$errors = [];
		$entityActivityCounter = new EntityActivityCounter(
			$this->getEntityTypeId(),
			array_keys($items),
			$errors,
		);
		$entityActivityCounter->appendToEntityItems($items);
	}

	public function getEntityTypeId(): int
	{
		return $this->entityTypeId;
	}

	protected function getEntityAttributes(array $items, string $columnIdName = 'id'): ?array
	{
		if (empty($items))
		{
			return null;
		}

		$ids = array_column($items, $columnIdName);
		$entityTypeName = $this->getEntityType();

		return Manager::resolveController($entityTypeName)->getPermissionAttributes($entityTypeName, $ids);
	}

	public function prepareFilterPresets(Entity $entity, array $presets, ?string $defaultPresetName): array
	{
		$results = [];

		foreach ($presets as $id => $preset)
		{
			$name = html_entity_decode($preset['name'] ?? '', ENT_QUOTES);

			if ($id === null || $id === 'default_filter' || $id === 'tmp_filter')
			{
				continue;
			}

			$default = ($id === $defaultPresetName);

			$results[] = compact('id', 'name', 'default');
		}

		return $results;
	}

	public function prepareFilter(Entity $entity): void
	{
		// can be implemented in a child class
	}

	public function getGridId(): string
	{
		$kanban = $this->getKanbanInstance();

		return $kanban->getEntity()->getGridId();
	}

	/**
	 * Settings of the filter the list is read through. The name of the list carries its funnel, and the
	 * settings are built out of that name: the data provider of a contact and of a company adds the
	 * funnel of its settings to every query (Crm\Filter\ContactDataProvider), so settings that name no
	 * funnel narrow any list to the default one and a list of another funnel comes back empty.
	 */
	protected function getFilterSettings(): EntitySettings
	{
		return Filter\Factory::getSettingsByGridId($this->getEntityTypeId(), $this->getGridId());
	}

	public function changeCategory(array $ids, int $categoryId): Result
	{
		$kanban = $this->getKanbanInstance();
		$userPermissions = $kanban->getCurrentUserPermissions();

		return $kanban->getEntity()->updateItemsCategory($ids, $categoryId, $userPermissions);
	}

	protected function getKanbanInstance(): Kanban
	{
		return Kanban::getInstance($this->getEntityType(), $this->getFilterParams());
	}

	protected function getFactory(int $entityTypeId): Factory
	{
		if (empty($this->factories[$entityTypeId]))
		{
			$this->factories[$entityTypeId] = Container::getInstance()->getFactory($entityTypeId);
		}

		return $this->factories[$entityTypeId];
	}

	public function getCurrentFilter()
	{
		return $this->params['filter'] ?? null;
	}

	/**
	 * @return string[]|null null means the strategy keeps its own legacy field rule
	 */
	protected function resolveCompactShowedFields(): ?array
	{
		return CompactViewResolver::resolveShowedFields($this->getEntityTypeId());
	}

	protected function createField(string $fieldName, Collection $fieldsCollection): Field
	{
		return DisplayFieldFactory::create($fieldName, $fieldsCollection);
	}
}
