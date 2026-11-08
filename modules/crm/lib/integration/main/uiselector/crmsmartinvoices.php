<?php

namespace Bitrix\Crm\Integration\Main\UISelector;

use Bitrix\Crm\Filter\ItemDataProvider;
use Bitrix\Crm\Filter\ItemSettings;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

class CrmSmartInvoices extends CrmDynamics
{
	public const PREFIX_SHORT = 'SI_';
	public const PREFIX_FULL = 'CRMSMART_INVOICE';
	private const SELECTED_ENTITIES_MAX_COUNT = 300;
	private const IDS_QUERY_CHUNK_SIZE = 300;

	protected static function getOwnerType(): int
	{
		return CCrmOwnerType::SmartInvoice;
	}

	protected static function getHandlerType(): string
	{
		return Handler::ENTITY_TYPE_CRMDYNAMICS;
	}

	protected static function prepareEntity(Item\Dynamic $item, ?array $options = []): array
	{
		$prefix = static::getPrefix($options);

		$date = $item->getBegindate() ?? $item->getCreatedTime();

		$result = [
			'id' => $prefix . $item->getId(),
			'entityType' => 'smart_invoices',
			'entityId' => $item->getId(),
			'name' => htmlspecialcharsbx($item->getHeading()),
			'desc' => '',
		];

		if ($date)
		{
			$result['date'] = $date->getTimestamp();
		}

		if (
			isset($options['returnItemUrl'])
			&& $options['returnItemUrl'] === 'Y'
		)
		{
			$result['url'] =
				Container::getInstance()->getRouter()->getItemDetailUrl(static::getOwnerType(), $item->getId())
			;
			$result['urlUseSlider'] = 'Y';
		}

		return $result;
	}

	public function getData($params = []): array
	{
		$entityType = Handler::ENTITY_TYPE_CRMSMART_INVOICES;

		$result = [
			'ITEMS' => [],
			'ITEMS_LAST' => [],
			'ITEMS_HIDDEN' => [],
			'ADDITIONAL_INFO' => [
				'GROUPS_LIST' => [
					'crmsmart_invoices' => [
						'TITLE' => $this->getGroupTitle(),
						'TYPE_LIST' => [ $entityType ],
						'DESC_LESS_MODE' => 'N',
						'SORT' => 40
					]
				],
				'SORT_SELECTED' => 400
			]
		];

		if (!InvoiceSettings::getCurrent()->isSmartInvoiceEnabled())
		{
			return $result;
		}

		$entityOptions = $params['options'] ?? [];

		$lastItems = $params['lastItems'] ?? [];
		$selectedItems = $params['selectedItems'] ?? [];

		$lastEntitiesIdList = [];
		if (!empty($lastItems[$entityType]))
		{
			$lastEntitiesIdList = $this->getEntityIdsByCodes(
				$lastItems[$entityType],
				$entityOptions,
				static::ENTITIES_MAX_COUNT
			);
			$result['ITEMS_LAST'] = $this->getEntityCodesByIds($lastEntitiesIdList, $entityOptions);
		}

		$selectedEntitiesIdList = [];
		if (!empty($selectedItems[$entityType]))
		{
			$selectedEntitiesIdList = $this->getEntityIdsByCodes(
				$selectedItems[$entityType],
				$entityOptions,
				self::SELECTED_ENTITIES_MAX_COUNT
			);
		}

		$entitiesIdList = array_merge($selectedEntitiesIdList, $lastEntitiesIdList);
		$entitiesIdList = array_unique($entitiesIdList);
		$entitiesIdList = array_slice(
			$entitiesIdList,
			0,
			count($selectedEntitiesIdList) + static::ENTITIES_MAX_COUNT
		);

		$entitiesList = [];

		if (!empty($entitiesIdList))
		{
			$entitiesList = $this->getByIdsResultItemsChunked($entitiesIdList, $entityOptions);
		}

		if (
			empty($lastEntitiesIdList)
			|| empty(array_intersect($result['ITEMS_LAST'], array_keys($entitiesList)))
		)
		{
			$lastEntitiesList = $this->getLastEntitiesResultItems($entityOptions);
			$result['ITEMS_LAST'] = array_keys($lastEntitiesList);
			$entitiesList += $lastEntitiesList;
		}

		$result['ITEMS'] = $entitiesList;

		return $result;
	}

	protected function getGroupTitle(): ?string
	{
		return Loc::getMessage('MAIN_UI_SELECTOR_TITLE_CRMSMART_INVOICE');
	}

	protected function getByIdsSelect(): array
	{
		return [
			Item::FIELD_NAME_ID,
			Item::FIELD_NAME_TITLE,
			Item::FIELD_NAME_BEGIN_DATE,
			Item::FIELD_NAME_CREATED_TIME,
		];
	}

	private function getLastEntitiesResultItems(array $entityOptions): array
	{
		$prefix = static::getPrefix($entityOptions);
		$entitiesList = [];

		$list = Container::getInstance()->getFactory(static::getOwnerType())->getItemsFilteredByPermissions([
			'order' => ['ID' => 'DESC'],
			'limit' => 10,
			'select' => $this->getByIdsSelect(),
		]);

		foreach ($list as $item)
		{
			$entitiesList[$prefix . $item['ID']] = static::prepareEntity($item, $entityOptions);
		}

		return $entitiesList;
	}

	private function getByIdsResultItemsChunked(array $ids, array $options): array
	{
		$result = [];

		foreach (array_chunk($ids, self::IDS_QUERY_CHUNK_SIZE) as $idsChunk)
		{
			$result += $this->getByIdsResultItems($idsChunk, $options);
		}

		return $result;
	}

	private function getEntityIdsByCodes(array $codes, array $options, ?int $limit = null): array
	{
		$ids = [];

		foreach ($codes as $code)
		{
			$id = $this->getEntityIdByCode((string)$code, $options);
			if ($id === null)
			{
				continue;
			}

			$ids[$id] = $id;
			if ($limit !== null && count($ids) >= $limit)
			{
				break;
			}
		}

		return array_values($ids);
	}

	private function getEntityIdByCode(string $code, array $options): ?int
	{
		$prefixList = [
			self::PREFIX_FULL,
			static::getPrefix($options),
			CrmDynamics::PREFIX_FULL . CCrmOwnerType::SmartInvoice . '_',
		];

		foreach ($prefixList as $prefix)
		{
			if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $code, $matches) === 1)
			{
				return (int)$matches[1];
			}
		}

		return null;
	}

	private function getEntityCodesByIds(array $ids, array $options): array
	{
		$prefix = static::getPrefix($options);

		return array_map(
			static fn(int $id): string => $prefix . $id,
			$ids
		);
	}

	public function getTabList($params = []): array
	{
		$result = [];

		$options = $params['options'] ?? [];

		if (
			isset($options['addTab'])
			&& $options['addTab'] === 'Y'
		)
		{
			$result = [
				[
					'id' => 'smart_invoices',
					'name' => CCrmOwnerType::GetDescription(static::getOwnerType()),
					'sort' => 40
				]
			];
		}

		return $result;
	}

	public function search($params = []): array
	{
		$result = [
			'ITEMS' => [],
			'ADDITIONAL_INFO' => []
		];

		if (!InvoiceSettings::getCurrent()->isSmartInvoiceEnabled())
		{
			return $result;
		}

		$entityOptions = $params['options'] ?? [];
		$requestFields = $params['requestFields'] ?? [];
		$search = $requestFields['searchString'];
		$prefix = static::getPrefix($entityOptions);

		if (
			$search <> ''
			&& (
				empty($entityOptions['enableSearch'])
				|| $entityOptions['enableSearch'] !== 'N'
			)
		)
		{
			$filter = $this->getSearchFilter($search, $entityOptions);

			if ($filter === false)
			{
				return $result;
			}

			$list = Container::getInstance()->getFactory(static::getOwnerType())->getItemsFilteredByPermissions([
				'order' => $this->getSearchOrder(),
				'select' => $this->getSearchSelect(),
				'limit' => 10,
				'filter' => $filter,
			]);

			$resultItems = [];
			foreach ($list as $item)
			{
				$resultItems[$prefix . $item->getId()] = static::prepareEntity($item, $entityOptions);
			}

			$resultItems = $this->appendItemsByIds($resultItems, $search, $entityOptions);

			$resultItems = $this->processResultItems($resultItems, $entityOptions);

			$result["ITEMS"] = $resultItems;
		}

		return $result;
	}

	protected function getSearchFilter(string $search, array $options)
	{
		$filter = [
			'?TITLE' => $search,
		];

		$entityTypeId = static::getOwnerType();
		$settings = new ItemSettings(
			['ID' => 'crm-element-field-' . $entityTypeId],
			Container::getInstance()->getTypeByEntityTypeId($entityTypeId)
		);
		$factory = Container::getInstance()->getFactory($entityTypeId);
		$provider = new ItemDataProvider($settings, $factory);
		$provider->prepareListFilter($filter, ['FIND' => $search]);

		return
			empty($filter)
				? false
				: $this->prepareOptionalFilter($filter, $options)
			;
	}

	protected function getByIdsResultItems(array $ids, array $options): array
	{
		$result = [];

		$prefix = static::getPrefix($options);

		$list = Container::getInstance()->getFactory(static::getOwnerType())->getItemsFilteredByPermissions(
			[
				'order' => $this->getByIdsOrder(),
				'select' => $this->getByIdsSelect(),
				'filter' => $this->getByIdsFilter($ids, $options),
			]
		);

		foreach ($list as $item)
		{
			$result[$prefix . $item->getId()] = static::prepareEntity($item, $options);
		}

		return $result;
	}

	protected static function getPrefix($options = []): string
	{
		if (!is_array($options))
		{
			$options = [];
		}

		$options['typeId'] = CCrmOwnerType::SmartInvoice;

		return parent::getPrefix($options);
	}
}
