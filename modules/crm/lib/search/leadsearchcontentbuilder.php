<?php

namespace Bitrix\Crm\Search;

use Bitrix\Crm\AddressTable;
use Bitrix\Crm\Entity\Index;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\Format\AddressFormatter;
use Bitrix\Crm\Item;
use Bitrix\Crm\LeadAddress;
use Bitrix\Crm\LeadTable;
use CCrmCurrency;
use CCrmLead;
use CCrmOwnerType;

final class LeadSearchContentBuilder extends SearchContentBuilder
{
	protected string $entityClassName = CCrmLead::class;
	protected string $entityIndexTableClassName = Index\LeadTable::class;
	protected string $entityIndexTable = 'b_crm_lead_index';
	protected string $entityIndexTablePrimaryColumn = 'LEAD_ID';

	private const SELECT_FIELDS = [
		Item::FIELD_NAME_ID,
		Item::FIELD_NAME_TITLE,
		Item::FIELD_NAME_LAST_NAME,
		Item::FIELD_NAME_NAME,
		Item::FIELD_NAME_SECOND_NAME,
		Item\Lead::FIELD_NAME_COMPANY_TITLE,
		Item::FIELD_NAME_OPPORTUNITY,
		Item::FIELD_NAME_CURRENCY_ID,
		Item::FIELD_NAME_ASSIGNED,
		Item\Lead::FIELD_NAME_STATUS_ID,
		Item\Lead::FIELD_NAME_STATUS_DESCRIPTION,
		Item::FIELD_NAME_SOURCE_ID,
		Item::FIELD_NAME_SOURCE_DESCRIPTION,
		Item::FIELD_NAME_COMMENTS,
		Item::FIELD_NAME_IS_RETURN_CUSTOMER,
	];

	private const INDEXED_ITEM_FIELDS = [
		Item::FIELD_NAME_TITLE,
		Item::FIELD_NAME_LAST_NAME,
		Item::FIELD_NAME_NAME,
		Item::FIELD_NAME_SECOND_NAME,
		Item\Lead::FIELD_NAME_COMPANY_TITLE,
		Item::FIELD_NAME_OPPORTUNITY,
		Item::FIELD_NAME_CURRENCY_ID,
		Item::FIELD_NAME_ASSIGNED,
		Item::FIELD_NAME_STAGE_ID,
		Item\Lead::FIELD_NAME_STATUS_DESCRIPTION,
		Item::FIELD_NAME_SOURCE_ID,
		Item::FIELD_NAME_SOURCE_DESCRIPTION,
		Item::FIELD_NAME_COMMENTS,
		Item::FIELD_NAME_IS_RETURN_CUSTOMER,
		Item::FIELD_NAME_FM,
	];

	private const ADDRESS_FIELDS = [
		'ADDRESS',
		'ADDRESS_2',
		'ADDRESS_CITY',
		'ADDRESS_POSTAL_CODE',
		'ADDRESS_REGION',
		'ADDRESS_PROVINCE',
		'ADDRESS_COUNTRY',
		'ADDRESS_COUNTRY_CODE',
		'ADDRESS_LOC_ADDR_ID',
	];

	/** @var array<int, array|null>|null primary addresses of the leads of the bulk build being run */
	private ?array $bulkAddresses = null;

	public function getEntityTypeId(): int
	{
		return CCrmOwnerType::Lead;
	}

	public function getSearchSelectFields(): ?array
	{
		return self::SELECT_FIELDS;
	}

	protected function getIndexedItemFieldNames(): ?array
	{
		return self::INDEXED_ITEM_FIELDS;
	}

	public function convertEntityFilterValues(array &$filter): void
	{
		$this->transferEntityFilterKeys(['FIND', 'PHONE'], $filter);
	}
	
	protected function prepareSearchMap(array $fields, array $options = null): SearchMap
	{
		$map = new SearchMap();
		$entityId = (int)($fields['ID'] ?? 0);
		if ($entityId <= 0)
		{
			return $map;
		}

		$isShortIndex = ($options['isShortIndex'] ?? false);
		if (!$isShortIndex)
		{
			$map->add($entityId);
		}

		$map = $this->addTitleToSearchMap(
			$entityId,
			$fields['TITLE'] ?? '',
			CCrmLead::GetDefaultTitleTemplate(),
			$map
		);

		$map->addField($fields, 'LAST_NAME');
		$map->addField($fields, 'NAME');
		$map->addField($fields, 'SECOND_NAME');

		if (!$isShortIndex)
		{
			$map->addField($fields, 'COMPANY_TITLE');
			$map->addField($fields, 'OPPORTUNITY');
			$map->add(CCrmCurrency::GetCurrencyName($fields['CURRENCY_ID'] ?? ''));

			if (isset($fields['ASSIGNED_BY_ID']))
			{
				$map->addUserByID($fields['ASSIGNED_BY_ID']);
			}
		}

		$map = $this->addPhonesAndEmailsToSearchMap($entityId, $map);

		if (!$isShortIndex)
		{
			//region Status
			if (isset($fields['STATUS_ID']))
			{
				$map->addStatus('STATUS', $fields['STATUS_ID']);
			}

			if (isset($fields['STATUS_DESCRIPTION']))
			{
				$map->addText($fields['STATUS_DESCRIPTION'], 1024);
			}
			//endregion

			//region Source
			if (isset($fields['SOURCE_ID']))
			{
				$map->addStatus('SOURCE', $fields['SOURCE_ID']);
			}

			if (isset($fields['SOURCE_DESCRIPTION']))
			{
				$map->addText($fields['SOURCE_DESCRIPTION'], 1024);
			}
			//endregion

			//region Address
            $address = preg_replace(
                '/[,.]/', '',
                AddressFormatter::getSingleInstance()->formatTextComma(
                    LeadAddress::mapEntityFields($fields)
                )
            );

			if ($address !== '')
			{
				$map->add($address);
			}
			//endregion

			if (isset($fields['COMMENTS']))
			{
				$map->addHtml($fields['COMMENTS'], 1024);
			}

			if (isset($fields['IS_RETURN_CUSTOMER']) && $fields['IS_RETURN_CUSTOMER'] === 'Y')
			{
				$map->add(CCrmLead::GetFieldCaption('IS_RETURN_CUSTOMER'));
			}

			//region UserFields
			$userFields = SearchEnvironment::getUserFields($entityId, $this->getUserFieldEntityId());
			foreach($userFields as $userField)
			{
				$map->addUserField($userField);
			}
			//endregion
		}

		return $map;
	}

	protected function prepareEntityFields(int $entityId): ?array
	{
		$fields = parent::prepareEntityFields($entityId);
		if ($fields === null)
		{
			return null;
		}

		return array_merge($fields, $this->prepareAddressFields($entityId));
	}

	public function bulkBuild(array $entityIds): void
	{
		try
		{
			parent::bulkBuild($entityIds);
		}
		finally
		{
			$this->bulkAddresses = null;
		}
	}

	protected function prepareForBulkBuild(array $entityIds): void
	{
		parent::prepareForBulkBuild($entityIds);

		$this->bulkAddresses = $this->loadPrimaryAddresses($entityIds);
	}

	/**
	 * @param int[] $leadIds
	 * @return array<int, array|null> the address by lead ID, null for a lead without one
	 */
	private function loadPrimaryAddresses(array $leadIds): array
	{
		$leadIds = array_values(array_unique(array_filter(array_map('intval', $leadIds), fn(int $id) => $id > 0)));
		$addresses = array_fill_keys($leadIds, null);
		if (empty($leadIds))
		{
			return $addresses;
		}

		$rows = AddressTable::getList([
			'filter' => [
				'=TYPE_ID' => EntityAddressType::Primary,
				'=ENTITY_TYPE_ID' => CCrmOwnerType::Lead,
				'@ENTITY_ID' => $leadIds,
			],
		]);
		while ($row = $rows->fetch())
		{
			$addresses[(int)$row['ENTITY_ID']] = $row;
		}

		return $addresses;
	}

	/**
	 * Primary address of the lead under the same keys and in the same form as the address join of
	 * CCrmLead::GetListEx gives it.
	 */
	private function prepareAddressFields(int $leadId): array
	{
		$address = (
			$this->bulkAddresses !== null && array_key_exists($leadId, $this->bulkAddresses)
				? $this->bulkAddresses[$leadId]
				: LeadAddress::getByOwner(EntityAddressType::Primary, CCrmOwnerType::Lead, $leadId)
		);

		$fields = [];
		foreach (self::ADDRESS_FIELDS as $key)
		{
			$value = $address[LeadAddress::mapEntityField($key, EntityAddressType::Primary)] ?? null;
			$fields[$key] = $value === null ? null : (string)$value;
		}

		return $fields;
	}

	protected function save(int $entityId, SearchMap $map): void
	{
		LeadTable::update(
			$entityId,
			$this->prepareUpdateData(LeadTable::getTableName(), $map->getString())
		);
	}
}
