<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Loader;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\Entity\Item\ContactBinding;
use Bitrix\Crm\V2\Public\Entity\Item\CompanyBinding;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\CompanyProvider;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Crm\V2\Internal\Service\Item\ItemFieldMapper;

class ItemLoader
{
	private const BATCH_SIZE = 500;
	/** @var array<string, array{0: \Bitrix\Rest\V3\Dto\Dto, 1: array<string|int, mixed>, 2: \Bitrix\Rest\V3\Dto\DtoFieldsCollection, 3: array<string, true>}> */
	private array $relatedDtoMappingContexts = [];

	public function __construct(
		private readonly ?int $userId = null,
	)
	{
	}

	/**
	 * @param Item[] $items
	 */
	public function load(array $items, ItemSelect $select): void
	{
		if (!$select->shouldLoadRelatedCrmObjects())
		{
			return;
		}

		$relatedSelect = $select->getRelatedCrmObjectsSelect();
		if (isset($relatedSelect['myCompany']))
		{
			$this->loadMyCompanies($items, $select);
		}

		$relatedFieldMap = $this->collectRelatedFieldMap($items, $select);
		if ($relatedFieldMap === [])
		{
			return;
		}

		[$groups, $groupKeysByField] = $this->collectRelationGroups($relatedFieldMap, $select);
		$loadedItemsByGroup = [];
		foreach ($groups as $groupKey => $group)
		{
			$entityTypeId = $group['entityTypeId'];
			$entityType = EntityType::fromId($entityTypeId);
			$loadedItemsByGroup[$groupKey] = [];
			$relatedItemSelect = $this->getRelatedItemSelect($select, $entityTypeId, $group['relationNames']);
			foreach (array_chunk(array_keys($group['ids']), self::BATCH_SIZE) as $idsChunk)
			{
				$loadedItems = $group['allowedCategoryIds'] === null
					? $this->loadRelatedItems($entityType, $idsChunk, $relatedItemSelect)
					: $this->loadRelatedItemsInCategories($entityType, $idsChunk, $relatedItemSelect, $group['allowedCategoryIds']);
				foreach ($loadedItems as $loadedItem)
				{
					$loadedItemId = $loadedItem->getId();
					if ($loadedItemId !== null)
					{
						$loadedItemsByGroup[$groupKey][$loadedItemId] = $loadedItem;
					}
				}
			}
		}

		foreach ($relatedFieldMap as $fieldName => $relatedByOwner)
		{
			foreach ($relatedByOwner as $ownerId => $relatedDescriptor)
			{
				$groupKey = $groupKeysByField[$fieldName] ?? null;
				$value = $groupKey === null
					? []
					: $this->resolveLoadedRelationValue(
						[$relatedDescriptor['entityTypeId'] => $loadedItemsByGroup[$groupKey]],
						$relatedDescriptor,
					);
				if (!$relatedDescriptor['multiple'])
				{
					$value = $value[0] ?? null;
				}
				$relatedDescriptor['owner']->internalSet($fieldName, $value);
			}
		}
	}

	/** @param int[] $ids */
	protected function loadRelatedItems(EntityType $entityType, array $ids, ItemSelect $select): iterable
	{
		return ItemProvider::forEntityType($entityType)
			->withAccessCheck($this->userId, AccessMode::Restricted)
			->getByIds($ids, $select)
		;
	}

	/**
	 * @param int[] $ids
	 * @param int[] $allowedCategoryIds
	 */
	protected function loadRelatedItemsInCategories(
		EntityType $entityType,
		array $ids,
		ItemSelect $select,
		array $allowedCategoryIds,
	): iterable
	{
		if ($allowedCategoryIds === [])
		{
			return [];
		}

		return ItemProvider::forEntityType($entityType)
			->withAccessCheck($this->userId, AccessMode::Restricted)
			->getList($select, new ItemFilter([
				'@id' => $ids,
				'@categoryId' => $allowedCategoryIds,
			]))
		;
	}

	/**
	 * @param Item[] $items
	 * @return array<string, array<int, array{owner: Item, entityTypeId: int|null, ids: int[], multiple: bool}>>
	 */
	private function collectRelatedFieldMap(array $items, ItemSelect $select): array
	{
		$result = [];
		foreach ($items as $item)
		{
			$ownerId = $item->getId();
			if ($ownerId === null)
			{
				continue;
			}

			foreach ($select->getRelatedCrmObjects() as $fieldName => $relatedDescriptor)
			{
				if ($fieldName === 'myCompany')
				{
					continue;
				}

				[$entityTypeId, $ids] = $this->resolveRelatedIds($item, $fieldName);
				$result[$fieldName][$ownerId] = [
					'owner' => $item,
					'entityTypeId' => $entityTypeId,
					'ids' => $ids,
					'multiple' => $relatedDescriptor['multiple'],
				];
			}
		}

		return $result;
	}

	/** @param string[]|null $relationNames */
	private function getRelatedItemSelect(ItemSelect $select, int $entityTypeId, ?array $relationNames = null): ItemSelect
	{
		$relationNames ??= array_keys(array_filter(
			$select->getRelatedCrmObjects(),
			static fn(string $fieldName): bool => $fieldName !== 'myCompany',
			ARRAY_FILTER_USE_KEY,
		));
		$fields = ['id'];
		$relatedEntityType = EntityType::fromId($entityTypeId);
		$fieldMapper = new ItemFieldMapper();
		$customFieldDataSelect = [];
		$loadMultifields = [];
		$loadContactBindings = false;
		$loadCompanyBindings = false;
		$loadObservers = false;
		$loadEmployee = false;
		$loadUtm = false;
		$dictionariesSelect = [];
		$loadCategoryForStage = false;
		$lastCommunicationSelect = [];
		foreach ($select->getRelatedCrmObjects() as $relatedFieldName => $relatedDescriptor)
		{
			if ($relationNames !== null && !in_array($relatedFieldName, $relationNames, true))
			{
				continue;
			}
			$relatedEntityTypeId = $relatedDescriptor['entityTypeId'] ?? $this->resolveRelatedFieldType($relatedFieldName);
			if ($relatedEntityTypeId !== $entityTypeId)
			{
				continue;
			}
			if ($relatedDescriptor['select'] === [])
			{
				array_push($fields, ...$this->getTitleSourceFields($entityTypeId));
				array_push($fields, ...$this->getUrlSourceFields($entityTypeId));
			}
			if ($relatedFieldName === 'myCompany')
			{
				$fields[] = 'isMyCompany';
			}

			foreach ($relatedDescriptor['select'] as $selectedField)
			{
				[$topLevelField] = explode('.', $selectedField, 2);
				$mappedTopLevelField = $fieldMapper->mapDtoFieldNameToItem($topLevelField) ?? $topLevelField;
				if (str_starts_with($topLevelField, 'UF_') && str_ends_with($topLevelField, '_data'))
				{
					$customFieldName = substr($topLevelField, 0, -strlen('_data'));
					$fields[] = $customFieldName;
					$customFieldDataSelect[$customFieldName] = [];

					continue;
				}
				if (ItemFieldMapper::requiresDictionaryLoader($topLevelField))
				{
					$dictionariesSelect[$topLevelField] = [];
					$loadCategoryForStage = $loadCategoryForStage
						|| in_array($topLevelField, ['stage', 'previousStage'], true);
				}
				if (ItemFieldMapper::isEmployeeRelationField($topLevelField))
				{
					$loadEmployee = true;
				}
				switch ($mappedTopLevelField)
				{
					case 'contactBindings':
						$loadContactBindings = true;

						break;
					case 'companyBindings':
						$loadCompanyBindings = true;

						break;
					case 'observers':
						$loadObservers = true;

						break;
					case 'phones':
						$loadMultifields['phone'] = true;

						break;
					case 'emails':
						$loadMultifields['email'] = true;

						break;
					case 'webs':
						$loadMultifields['web'] = true;

						break;
					case 'ims':
						$loadMultifields['im'] = true;

						break;
					case 'utm':
						$loadUtm = true;

						break;
					case 'lastCommunication':
						$lastCommunicationField = explode('.', $selectedField, 2)[1] ?? null;
						if ($lastCommunicationField !== null)
						{
							$lastCommunicationSelect[] = $lastCommunicationField;
						}

						break;
					case 'id':
					case 'entityTypeId':
					case 'canRead':
						break;
					case 'stage':
					case 'previousStage':
					case 'stageSemantic':
					case 'source':
					case 'category':
					case 'currency':
					case 'webform':
						break;
					case 'title':
						array_push($fields, ...$this->getTitleSourceFields($entityTypeId));

						break;
					case 'url':
						array_push($fields, ...$this->getUrlSourceFields($entityTypeId));

						break;
					default:
						$fields[] = $mappedTopLevelField;
				}
			}
		}
		if (EntityTypeSettings::of($relatedEntityType)->isCategoriesSupported())
		{
			$fields[] = 'categoryId';
		}

		$relatedSelect = new ItemSelect(...array_values(array_unique($fields)));
		$relatedSelect->withContactBindings($loadContactBindings);
		$relatedSelect->withCompanyBindings($loadCompanyBindings);
		$relatedSelect->withEmployee($loadEmployee);
		$relatedSelect->withObservers($loadObservers);
		foreach (array_keys($loadMultifields) as $multifield)
		{
			match ($multifield)
			{
				'phone' => $relatedSelect->withPhones(),
				'email' => $relatedSelect->withEmails(),
				'web' => $relatedSelect->withWebs(),
				'im' => $relatedSelect->withIms(),
			};
		}
		if ($loadUtm)
		{
			$relatedSelect->withUtm();
		}
		if ($dictionariesSelect !== [])
		{
			$relatedSelect->withDictionaries($dictionariesSelect);
		}
		if ($lastCommunicationSelect !== [] || $this->hasWholeRelationSelected($select, $entityTypeId, 'lastCommunication'))
		{
			$relatedSelect->withLastCommunication(array_values(array_unique($lastCommunicationSelect)));
		}
		if ($customFieldDataSelect !== [])
		{
			$relatedSelect->withCustomFieldData($customFieldDataSelect);
		}

		return $relatedSelect;
	}

	private function hasWholeRelationSelected(ItemSelect $select, int $entityTypeId, string $fieldName): bool
	{
		foreach ($select->getRelatedCrmObjects() as $relatedFieldName => $relatedDescriptor)
		{
			$relatedEntityTypeId = $relatedDescriptor['entityTypeId'] ?? $this->resolveRelatedFieldType($relatedFieldName);
			if ($relatedEntityTypeId === $entityTypeId && in_array($fieldName, $relatedDescriptor['select'], true))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return string[]
	 */
	private function getTitleSourceFields(int $entityTypeId): array
	{
		if ($entityTypeId === EntityType::contact()->getId())
		{
			return ['name', 'lastName', 'secondName'];
		}

		return ['title'];
	}

	/**
	 * @return string[]
	 */
	private function getUrlSourceFields(int $entityTypeId): array
	{
		if (EntityTypeSettings::of(EntityType::fromId($entityTypeId))->isCategoriesSupported())
		{
			return ['categoryId'];
		}

		return [];
	}

	private function resolveRelatedFieldType(string $fieldName): ?int
	{
		return match (true)
		{
			$fieldName === 'company' => EntityType::company()->getId(),
			$fieldName === 'myCompany' => EntityType::company()->getId(),
			$fieldName === 'deal' => EntityType::deal()->getId(),
			$fieldName === 'lead' => EntityType::lead()->getId(),
			$fieldName === 'quote' => EntityType::quote()->getId(),
			$fieldName === 'contact' || $fieldName === 'contacts' => EntityType::contact()->getId(),
			$fieldName === 'companies' => EntityType::company()->getId(),
			str_starts_with($fieldName, 'related') => EntityType::fromCode(
				substr($fieldName, strlen('related')),
			)?->getId(),
			default => null,
		};
	}

	/**
	 * @param array<string, array<int, array{owner: Item, entityTypeId: int|null, ids: int[], multiple: bool}>> $relatedFieldMap
	 * @return array{0: array<string, array{entityTypeId: int, allowedCategoryIds: int[]|null, relationNames: string[], ids: array<int, true>}>, 1: array<string, string>}
	 */
	private function collectRelationGroups(array $relatedFieldMap, ItemSelect $select): array
	{
		$groups = [];
		$groupKeysByField = [];
		$selectByField = $select->getRelatedCrmObjects();
		foreach ($relatedFieldMap as $fieldName => $relatedByOwner)
		{
			$allowedCategoryIds = $selectByField[$fieldName]['allowedCategoryIds'] ?? null;
			if ($allowedCategoryIds !== null)
			{
				sort($allowedCategoryIds);
			}
			foreach ($relatedByOwner as $relatedDescriptor)
			{
				$entityTypeId = $relatedDescriptor['entityTypeId'];
				if ($entityTypeId === null)
				{
					continue;
				}
				$groupKey = $entityTypeId . ':' . ($allowedCategoryIds === null ? '*' : implode(',', $allowedCategoryIds));
				$groupKeysByField[$fieldName] = $groupKey;
				$groups[$groupKey] ??= [
					'entityTypeId' => $entityTypeId,
					'allowedCategoryIds' => $allowedCategoryIds,
					'relationNames' => [],
					'ids' => [],
				];
				$groups[$groupKey]['relationNames'][$fieldName] = $fieldName;

				foreach ($relatedDescriptor['ids'] as $id)
				{
					if ($id > 0)
					{
						$groups[$groupKey]['ids'][$id] = true;
					}
				}
			}
		}

		foreach ($groups as &$group)
		{
			$group['relationNames'] = array_values($group['relationNames']);
		}
		unset($group);

		return [$groups, $groupKeysByField];
	}

	/**
	 * @return array{0: int|null, 1: int[]}
	 */
	private function resolveRelatedIds(Item $item, string $fieldName): array
	{
		if ($fieldName === 'company')
		{
			$companyId = method_exists($item, 'getCompanyId') ? (int)$item->getCompanyId() : 0;

			return [EntityType::company()->getId(), $companyId > 0 ? [$companyId] : []];
		}

		if ($fieldName === 'deal')
		{
			$dealId = method_exists($item, 'getDealId') ? (int)$item->getDealId() : 0;

			return [EntityType::deal()->getId(), $dealId > 0 ? [$dealId] : []];
		}

		if ($fieldName === 'lead')
		{
			$leadId = method_exists($item, 'getLeadId') ? (int)$item->getLeadId() : 0;

			return [EntityType::lead()->getId(), $leadId > 0 ? [$leadId] : []];
		}

		if ($fieldName === 'quote')
		{
			$quoteId = method_exists($item, 'getQuoteId') ? (int)$item->getQuoteId() : 0;

			return [EntityType::quote()->getId(), $quoteId > 0 ? [$quoteId] : []];
		}

		if ($fieldName === 'contact' || $fieldName === 'contacts')
		{
			$contactBindings = method_exists($item, 'getContactBindings') ? $item->getContactBindings() : null;
			if ($contactBindings === null)
			{
				return [EntityType::contact()->getId(), []];
			}

			if ($fieldName === 'contact')
			{
				$primaryContactId = $contactBindings->getPrimary()?->getContactId() ?? 0;

				return [EntityType::contact()->getId(), $primaryContactId > 0 ? [$primaryContactId] : []];
			}

			$contactIds = array_map(
				static fn(ContactBinding $binding) => $binding->getContactId(),
				$contactBindings->getAll(),
			);

			return [EntityType::contact()->getId(), array_values(array_filter($contactIds, static fn(int $id) => $id > 0))];
		}

		if ($fieldName === 'companies')
		{
			$companyBindings = method_exists($item, 'getCompanyBindings') ? $item->getCompanyBindings() : null;
			if ($companyBindings === null)
			{
				return [EntityType::company()->getId(), []];
			}

			$companyIds = array_map(
				static fn(CompanyBinding $binding) => $binding->getCompanyId(),
				$companyBindings->getAll(),
			);

			return [EntityType::company()->getId(), array_values(array_filter($companyIds, static fn(int $id) => $id > 0))];
		}

		if (!str_starts_with($fieldName, 'related'))
		{
			return [null, []];
		}

		$entityCode = substr($fieldName, strlen('related'));
		$entityType = EntityType::fromCode($entityCode);
		if ($entityType === null)
		{
			return [null, []];
		}

		$relatedId = $item->getParentId($entityType) ?? 0;

		return [$entityType->getId(), $relatedId > 0 ? [$relatedId] : []];
	}

	/**
	 * @param array<int, array<int, Item>> $loadedItemsByEntityType
	 * @param array{owner: Item, entityTypeId: int|null, ids: int[]} $relatedDescriptor
	 * @return array<int, Item>
	 */
	private function resolveLoadedRelationValue(array $loadedItemsByEntityType, array $relatedDescriptor): array
	{
		$entityTypeId = $relatedDescriptor['entityTypeId'];
		if ($entityTypeId === null || $relatedDescriptor['ids'] === [])
		{
			return [];
		}

		$itemsById = $loadedItemsByEntityType[$entityTypeId] ?? [];
		$result = [];
		foreach ($relatedDescriptor['ids'] as $id)
		{
			$loadedItem = $itemsById[$id] ?? null;
			if ($loadedItem !== null)
			{
				$result[] = $loadedItem;
			}
		}

		return $result;
	}

	/**
	 * @param Item[] $items
	 * @param string[] $select
	 */
	private function loadMyCompanies(array $items, ItemSelect $select): void
	{
		$myCompanyIdsByOwnerId = $this->collectMyCompanyIdsByOwnerId($items);
		$canReadBaseFields = $this->userId === null || Container::getInstance()
			->getUserPermissions($this->userId)
			->myCompany()
			->canReadBaseFields()
		;
		$companiesById = $myCompanyIdsByOwnerId === []
			? []
			: $this->loadMyCompaniesById(
				array_values(array_unique($myCompanyIdsByOwnerId)),
				$this->getRelatedItemSelect($select, EntityType::company()->getId(), ['myCompany']),
			)
		;
		$restrictedMyCompanyIds = array_values(array_filter(
			array_unique($myCompanyIdsByOwnerId),
			static fn(int $id): bool => !isset($companiesById[$id]) || !$companiesById[$id]->canRead(),
		));
		$companiesById = array_filter(
			$companiesById,
			static fn(Company $company): bool => $company->getIsMyCompany() === true,
		);
		if (!$canReadBaseFields && $restrictedMyCompanyIds !== [])
		{
			$companiesById = $this->restoreRestrictedMyCompanies(
				$companiesById,
				$this->loadMyCompaniesById(
					$restrictedMyCompanyIds,
					new ItemSelect('id', 'isMyCompany'),
					false,
				),
			);
		}
		if ($canReadBaseFields && $restrictedMyCompanyIds !== [])
		{
			$baseCompaniesById = $this->loadMyCompaniesById(
				$restrictedMyCompanyIds,
				$this->getBaseMyCompanySelect($select),
				false,
			);
			foreach ($baseCompaniesById as $id => $baseCompany)
			{
				$company = $companiesById[$id] ?? null;
				if (($company === null || !$company->canRead()) && $baseCompany->getIsMyCompany() === true)
				{
					$companiesById[$id] = $this->toBaseRestrictedMyCompany($baseCompany);
				}
			}
		}

		foreach ($items as $item)
		{
			$ownerId = $item->getId();
			if ($ownerId === null || !isset($myCompanyIdsByOwnerId[$ownerId]))
			{
				$item->internalSet('myCompany', null);

				continue;
			}

			$myCompany = $companiesById[$myCompanyIdsByOwnerId[$ownerId]] ?? null;
			if ($myCompany !== null && !$canReadBaseFields)
			{
				$myCompany = $this->toRestrictedMyCompany($myCompany);
			}

			$item->internalSet('myCompany', $myCompany);
		}
	}

	/**
	 * @param array<int, Company> $companiesById
	 * @param array<int, Company> $identityCompaniesById
	 * @return array<int, Company>
	 */
	private function restoreRestrictedMyCompanies(array $companiesById, array $identityCompaniesById): array
	{
		foreach ($identityCompaniesById as $id => $identityCompany)
		{
			if ($identityCompany->getIsMyCompany() === true)
			{
				$companiesById[$id] = $this->toRestrictedMyCompany($identityCompany);
			}
		}

		return $companiesById;
	}

	/**
	 * @param Item[] $items
	 * @return array<int, int>
	 */
	private function collectMyCompanyIdsByOwnerId(array $items): array
	{
		$result = [];
		foreach ($items as $item)
		{
			$ownerId = $item->getId();
			if ($ownerId === null || !method_exists($item, 'getMyCompanyId'))
			{
				continue;
			}

			$myCompanyId = (int)$item->getMyCompanyId();
			if ($myCompanyId > 0)
			{
				$result[$ownerId] = $myCompanyId;
			}
		}

		return $result;
	}

	/**
	 * @param int[] $ids
	 * @param string[] $select
	 * @return array<int, Company>
	 */
	private function loadMyCompaniesById(array $ids, ItemSelect $select, bool $withAccessCheck = true): array
	{
		$result = [];
		foreach (array_chunk($ids, self::BATCH_SIZE) as $idsChunk)
		{
			$provider = new CompanyProvider();
			if ($withAccessCheck && $this->userId !== null)
			{
				$provider = $provider->withAccessCheck($this->userId, AccessMode::Restricted);
			}

			$collection = $provider->getByIds($idsChunk, $select);
			foreach ($collection as $company)
			{
				if (!$company instanceof Company || $company->getId() === null)
				{
					continue;
				}

				$result[(int)$company->getId()] = $company;
			}
		}

		return $result;
	}

	private function getBaseMyCompanySelect(ItemSelect $select): ItemSelect
	{
		$selectedFields = $select->getRelatedCrmObjects()['myCompany']['select'] ?? [];
		$fields = ['id', 'isMyCompany'];
		if ($selectedFields === [] || in_array('title', $selectedFields, true))
		{
			array_push($fields, ...$this->getTitleSourceFields(EntityType::company()->getId()));
		}
		if ($selectedFields === [] || in_array('url', $selectedFields, true))
		{
			array_push($fields, ...$this->getUrlSourceFields(EntityType::company()->getId()));
		}

		return new ItemSelect(...array_values(array_unique($fields)));
	}

	private function toRestrictedMyCompany(Company $company): Company
	{
		$restricted = new Company();
		$id = $company->getId();
		if ($id !== null)
		{
			$restricted->setId($id);
		}
		$restricted->markAsRestricted();

		return $restricted;
	}

	private function toBaseRestrictedMyCompany(Company $company): Company
	{
		$restricted = (new Company())
			->setId((int)$company->getId())
			->setTitle($company->getTitle())
			->setCategoryId($company->getCategoryId())
		;
		$restricted->markAsRestrictedWithBaseFields();

		return $restricted;
	}

}
