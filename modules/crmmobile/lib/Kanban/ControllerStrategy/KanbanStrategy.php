<?php

namespace Bitrix\CrmMobile\Kanban\ControllerStrategy;

use Bitrix\Crm\Integration\OpenLineManager;
use Bitrix\Crm\Kanban\Entity;
use Bitrix\Crm\Kanban\EntityBadge;
use Bitrix\Crm\Service\Display\Field;
use Bitrix\CrmMobile\Public\Feature\UnifiedFiltersFlag;
use Bitrix\CrmMobile\Kanban\Client;
use Bitrix\CrmMobile\Kanban\GridId;
use Bitrix\CrmMobile\Kanban\Kanban;
use Bitrix\CrmMobile\Kanban\View\CompactDisplayValues;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Filter\FilterRequest;

final class KanbanStrategy extends Base
{
	private const CLIENT_FIELDS_TO_SELECT = [
		'EMAIL',
		'PHONE',
		'IM',
	];

	public function updateItemStage(int $id, int $stageId): Result
	{
		$kanban = $this->getKanbanInstance();
		$stages = $kanban->getStatuses(true);

		$statusId = null;
		foreach ($stages as $stage)
		{
			if ((int)$stage['ID'] === $stageId)
			{
				$statusId = $stage['STATUS_ID'];
				break;
			}
		}

		if (!$statusId)
		{
			$result = new Result();
			$result->addError(new Error('Column: ' . $stageId . ' not found'));

			return $result;
		}

		return $kanban->getEntity()->updateItemStage($id, $statusId, [], $stages);
	}

	public function deleteItem(int $id, array $params = []): Result
	{
		$this->getKanbanInstance()->getEntity()->deleteItems([$id], false, null, $params);

		// I think that after switching to a new api in kanban,
		// we will be able to receive the result of the deletion
		return new Result();
	}

	public function getList(?PageNavigation $pageNavigation): array
	{
		if (empty($this->params))
		{
			throw new ArgumentException('Set params first');
		}

		$contactDataProvider = new Client\DataProvider(\CCrmOwnerType::Contact);
		$companyDataProvider = new Client\DataProvider(\CCrmOwnerType::Company);

		$contactDataProvider->addFieldsToSelect(self::CLIENT_FIELDS_TO_SELECT);
		$companyDataProvider->addFieldsToSelect(self::CLIENT_FIELDS_TO_SELECT);

		$kanban = $this
			->getKanbanInstance()
			->setFieldsContext(Field::MOBILE_CONTEXT)
			->setContactEntityDataProvider($contactDataProvider)
			->setCompanyEntityDataProvider($companyDataProvider)
		;

		$searchContent = (string)($this->params['filter']['search'] ?? '');
		$filter = ['SEARCH_CONTENT' => $searchContent];

		$entityTypeId = $this->entityTypeId;
		$entity = $kanban->getEntity()->setGridIdInstance(new GridId($entityTypeId), $entityTypeId);
		$stageFieldName = $entity->getStageFieldName();

		$filterParams = $this->params['filterParams'];
		$statusId = ($filterParams['stageId'] ?? null);
		$presetId = (string)($filterParams['FILTER_PRESET_ID'] ?? '');

		if ($statusId)
		{
			$filter[$stageFieldName] = $statusId;
		}
		elseif ($presetId === '' && Feature::isDisabled(UnifiedFiltersFlag::class))
		{
			// FORCE_FILTER drops every condition but the category (crm Kanban::getFilter): the right
			// answer while no preset is named, unless the filter stored for the list is to be kept -
			// the unified client leaves the id empty on purpose. Same decision as Category::getFilterParams
			$filterParams['FORCE_FILTER'] = 'Y';
		}

		if (isset($filterParams['ID']))
		{
			$filter['@ID'] = $filterParams['ID'];
		}

		if ($presetId !== '')
		{
			// crm Kanban::getFilter() applies the preset the params name, and `default_filter` names
			// no conditions at all: the id has to be the resolved one prepareFilter() writes under
			$filterParams['FILTER_PRESET_ID'] = FilterRequest::resolvePresetId($presetId);
		}

		$this->prepareFilter($entity);

		$currentPage = ($pageNavigation ? $pageNavigation->getCurrentPage() : 1);
		$itemsResult = $kanban->getItems($filter, $currentPage, ['filter' => $filterParams]);
		$this->prepareItemsResult($itemsResult['ITEMS'], $kanban);

		$this->prepareActivityCounters($itemsResult['ITEMS']);
		$this->prepareItemsBadges($itemsResult['ITEMS']);

		return $itemsResult['ITEMS'];
	}

	public function prepareFilter(Entity $entity): void
	{
		$filterParams = ($this->params['filterParams'] ?? []);
		$presetId = ($filterParams['FILTER_PRESET_ID'] ?? null);

		if ($presetId === null || $presetId === '')
		{
			return;
		}

		$this->setFilterPreset($presetId, $entity->getFilterOptions());
	}

	protected function prepareItemsResult(array &$items, Kanban $kanban): void
	{
		$kanban->getEntity()->appendMultiFieldData($items, $kanban->getAllowedFmTypes());

		foreach ($items as &$item)
		{
			$contactTypes = ['phone', 'email', 'im'];
			$hasContacts = false;
			foreach ($contactTypes as $contactType)
			{
				if (!empty($item[$contactType]))
				{
					$hasContacts = true;
					break;
				}
			}

			if (!$hasContacts)
			{
				continue;
			}

			$data = [
				'id' => $item['id'],
				'title' => $item['name'],
				'type' => strtolower($kanban->getEntity()->getTypeName()),
				'hidden' => false,
				'phone' => [],
				'email' => [],
				'im' => [],
			];

			foreach ($contactTypes as $contactType)
			{
				if (empty($item[$contactType]))
				{
					continue;
				}

				if (is_array($item[$contactType]))
				{
					foreach ($item[$contactType] as $contactItemKey => $contactItemValue)
					{
						if (!isset($item['client']) || array_keys($item['client']) === ['hidden'])
						{
							$title = '';
							if (is_string($contactItemValue['value']) && OpenLineManager::isImOpenLinesValue($contactItemValue['value']))
							{
								$title = OpenLineManager::getOpenLineTitle($contactItemValue['value']);
							}

							$data[$contactType][] = [
								'value' => $contactItemValue['value'],
								'complexName' => $contactItemValue['title'],
								'title' => $title,
							];
						}

						// hidden contacts
						if (isset($item['client']) && \CCrmOwnerTypeAbbr::ResolveByTypeName($contactItemKey))
						{
							foreach ($contactItemValue as $key => $communicationData)
							{
								if (
									!isset($item['client'][$contactItemKey][0][$contactType][$key])
									&& is_array($communicationData)
								)
								{
									$item['client'][$contactItemKey][0][$contactType][$key] =
										[
											'value' => $communicationData['value'],
											'complexName' => $communicationData['title'],
											'title' => '',
										];
								}
							}
						}
					}
				}
			}

			$item['client'][$kanban->getEntity()->getTypeName()] = [$data];
		}

		unset($item);
	}

	protected function prepareItemsBadges(array &$items): void
	{
		if (empty($items))
		{
			return;
		}

		$entityBadges = new EntityBadge(
			$this->getEntityTypeId(),
			array_keys($items)
		);
		$entityBadges->appendToEntityItems($items);
	}

	public function getItemParams(array $items): array
	{
		$entityAttributes = $this->getEntityAttributes($items);

		/*
		 * Temporarily hidden the ability to render time under the option for demo.
		 * Most likely this will not be required and the time will not be rendered constantly
		 */
		$renderLastActivityTime = (Option::get('crmmobile', 'render_last_activity_time_in_kanban_items', 'N') === 'Y');

		$params = [
			'permissionEntityAttributes' => $entityAttributes,
			'renderLastActivityTime' => $renderLastActivityTime,
		];

		$compactFieldsNames = $this->resolveCompactShowedFields();
		if ($compactFieldsNames === null)
		{
			return $params;
		}

		// The kanban select is built from the card settings of the current user and never holds the compact
		// field set, so its values are loaded and formatted from scratch.
		$compactValues = new CompactDisplayValues($this->getFactory($this->getEntityTypeId()));

		return array_merge($params, $compactValues->getItemParams(array_keys($items), $compactFieldsNames));
	}
}
