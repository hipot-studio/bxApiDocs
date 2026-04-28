<?php

namespace Bitrix\Sign\Service\Document\Placeholder;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Item\Document\Placeholder\HcmLinkCompany;
use Bitrix\Sign\Item\Document\Placeholder\HcmLinkCompanyCollection;
use Bitrix\Sign\Item\Document\Placeholder\HcmLinkPlaceholderNameConfig;
use Bitrix\Sign\Item\Document\Placeholder\HcmLinkPlaceholders;
use Bitrix\Sign\Item\Document\Placeholder\Placeholder;
use Bitrix\Sign\Item\Document\Placeholder\PlaceholderCollection;
use Bitrix\Sign\Item\Document\Placeholder\PlaceholderNameConfig;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Sign\Service\Document\FieldService;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\CrmReferencePlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\EmployeeDynamicPlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\HcmLinkPlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\UserFieldsPlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Integration\Crm\MyCompanyService;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkFieldService;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkService;
use Bitrix\Sign\Service\Placeholder\FieldAlias\FieldAliasService;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\BlockParty;
use Bitrix\Sign\Ui\PlaceholderGrid\SectionBuilder;

class PlaceholderCollectorService
{
	private const PRESET_ID = 1;

	public function __construct(
		private readonly FieldService $fieldService,
		private readonly MyCompanyService $myCompanyService,
		private readonly HcmLinkFieldService $hcmLinkFieldService,
		private readonly HcmLinkService $hcmLinkService,
		private readonly PlaceholderCacheService $placeholderCacheService,
		private readonly SectionBuilder $sectionBuilder,
		private readonly FieldAliasService $fieldAliasService,
	)
	{
	}

	public function loadPlaceholdersByUserId(int $userId, bool $clearCache = false): Result
	{
		$loadPlaceholdersResult = new Result();

		if ($clearCache)
		{
			$this->placeholderCacheService->invalidateDocumentPlaceholderListCache();
		}

		$placeholdersFromCache = $this->placeholderCacheService->getPlaceholderList();
		if ($placeholdersFromCache !== null)
		{
			return $loadPlaceholdersResult->setData($placeholdersFromCache);
		}

		$fieldsDataResult = $this->fieldService->loadByUserId($userId, $this->getDocumentFieldOptions());
		if (!$fieldsDataResult->isSuccess())
		{
			return $loadPlaceholdersResult->addErrors($fieldsDataResult->getErrors());
		}

		$fieldsData = $fieldsDataResult->getData();
		$placeholders = $this->createPlaceholdersFromFields($fieldsData);
		$placeholders['externalB2eDocument'] = Application::getInstance()->getLicense()->getRegion() === 'ru'
			? $this->getExternalB2eDocumentPlaceholders()
			: new PlaceholderCollection()
		;
		
		$placeholders = $this->convertAllPlaceholdersToAliases($placeholders);

		$sections = $this->sectionBuilder->buildSections($placeholders);
		$loadPlaceholdersResult->setData($sections);
		$this->placeholderCacheService->setPlaceholderList($sections);

		return $loadPlaceholdersResult;
	}

	public function loadPlaceholdersByHcmLinkCompanyId(int $hcmLinkCompanyId): Result
	{
		$loadPlaceholdersResult = new Result();
		$placeholdersFromCache = $this->placeholderCacheService->getPlaceholderListByHcmLinkCompanyId($hcmLinkCompanyId);
		if ($placeholdersFromCache !== null)
		{
			return $loadPlaceholdersResult->setData($placeholdersFromCache);
		}

		$hcmLinkPlaceholders = $this->createHcmLinkPlaceholders($hcmLinkCompanyId);
		if (!$hcmLinkPlaceholders)
		{
			return $loadPlaceholdersResult;
		}
		
		$hcmLinkPlaceholders = $this->convertHcmLinkPlaceholdersToAliases($hcmLinkPlaceholders);

		$sections = $this->sectionBuilder->buildHcmLinkSections($hcmLinkPlaceholders);
		$loadPlaceholdersResult->setData($sections);
		$this->placeholderCacheService->setPlaceholderListByHcmLinkCompanyId($hcmLinkCompanyId, $sections);

		return $loadPlaceholdersResult;
	}

	private function isHcmLinkAvailable(): bool
	{
		if (!$this->hcmLinkService->isAvailable())
		{
			return false;
		}

		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		return true;
	}

	private function getDocumentFieldOptions(): array
	{
		return [
			'hideVirtual' => true,
			'hideRequisites' => false,
			'hideSmartB2eDocument' => true,
			'presetId' => self::PRESET_ID,
		];
	}

	/**
	 * @return array<string, PlaceholderCollection>
	 */
	private function createPlaceholdersFromFields(array $fieldsData): array
	{
		$placeholders = [];
		foreach ($this->getPlaceholderNameConfigs() as $config)
		{
			$fields = $fieldsData['fields'][$config->dataKey]['FIELDS'] ?? [];
			$placeholders[$config->resultKey] = $config->strategy->createFromFields($fields, $config->party);
		}

		return $placeholders;
	}

	/**
	 * Converts all placeholder collections to aliases
	 * Automatically determines party from field names
	 * @param array<string, PlaceholderCollection> $placeholdersMap Map of placeholder collections
	 * @return array<string, PlaceholderCollection> New map with all placeholders converted to aliases
	 */
	private function convertAllPlaceholdersToAliases(array $placeholdersMap): array
	{
		return array_map(function ($collection) {
			return $this->convertPlaceholderCollection($collection);
		}, $placeholdersMap);
	}

	/**
	 * Converts HcmLink placeholder collections to aliases
	 * This method traverses the structure and applies alias conversion to all PlaceholderCollections
	 * @param HcmLinkPlaceholders $hcmLinkPlaceholders HcmLink placeholders with full field names
	 * @return HcmLinkPlaceholders New HcmLinkPlaceholders with all placeholders converted to aliases
	 */
	private function convertHcmLinkPlaceholdersToAliases(HcmLinkPlaceholders $hcmLinkPlaceholders): HcmLinkPlaceholders
	{
		return new HcmLinkPlaceholders(
			employee: $this->convertHcmLinkCompanyCollection($hcmLinkPlaceholders->employee),
			representative: $this->convertHcmLinkCompanyCollection($hcmLinkPlaceholders->representative),
		);
	}
	
	/**
	 * Converts a single HcmLinkCompanyCollection
	 * Helper method that processes all companies in the collection
	 * @param HcmLinkCompanyCollection $companyCollection Collection to convert
	 * @return HcmLinkCompanyCollection Converted collection
	 */
	private function convertHcmLinkCompanyCollection(HcmLinkCompanyCollection $companyCollection): HcmLinkCompanyCollection
	{
		$result = new HcmLinkCompanyCollection();
		
		foreach ($companyCollection as $company)
		{
			$convertedItems = $this->convertPlaceholderCollection($company->items);
			$result->add(new HcmLinkCompany(
				$company->hcmLinkTitle,
				$company->myCompanyTitle,
				$convertedItems,
			));
		}
		
		return $result;
	}
	
	/**
	 * Converts a single PlaceholderCollection to aliases
	 * @param PlaceholderCollection $collection Collection to convert
	 * @return PlaceholderCollection Converted collection
	 */
	private function convertPlaceholderCollection(PlaceholderCollection $collection): PlaceholderCollection
	{
		$result = new PlaceholderCollection();
		
		foreach ($collection as $placeholder)
		{
			$parsed = NameHelper::parse($placeholder->value);
			$party = $parsed['party'] ?? BlockParty::LAST_PARTY;
			$context = AliasContext::empty()->withParty($party);
			$alias = $this->fieldAliasService->toAlias($placeholder->value, $context);
			$aliasValue = $alias ?? $placeholder->value;
			
			$result->add(new Placeholder(
				$placeholder->name,
				$aliasValue,
			));
		}
		
		return $result;
	}

	/**
	 * @return PlaceholderNameConfig[]
	 */
	private function getPlaceholderNameConfigs(): array
	{
		return [
			new PlaceholderNameConfig(
				'COMPANY',
				new CrmReferencePlaceholderCollectorStrategy(),
				BlockParty::NOT_LAST_PARTY,
				'company',
			),
			new PlaceholderNameConfig(
				'SMART_B2E_DOC',
				new CrmReferencePlaceholderCollectorStrategy(),
				BlockParty::NOT_LAST_PARTY,
				'b2eDocument',
			),
			new PlaceholderNameConfig(
				'PROFILE',
				new UserFieldsPlaceholderCollectorStrategy(),
				BlockParty::NOT_LAST_PARTY,
				'representative',
			),
			new PlaceholderNameConfig(
				'PROFILE',
				new UserFieldsPlaceholderCollectorStrategy(),
				BlockParty::LAST_PARTY,
				'employee',
			),
			new PlaceholderNameConfig(
				'DYNAMIC_MEMBER',
				new EmployeeDynamicPlaceholderCollectorStrategy(),
				BlockParty::LAST_PARTY,
				'employeeDynamic',
			),
		];
	}

	private function createHcmLinkPlaceholders(int $hcmLinkCompanyId): ?HcmLinkPlaceholders
	{
		if (!$this->isHcmLinkAvailable())
		{
			return null;
		}

		$hcmLinkCompany = $this->hcmLinkService->getById($hcmLinkCompanyId);
		$fields = $this->hcmLinkFieldService->getFieldsForSelector($hcmLinkCompanyId);

		$myCompanies = $this->myCompanyService->listWithTaxIds();
		$myCompany = $myCompanies->findById($hcmLinkCompany->myCompanyId);
		$myCompanyTitle = $myCompany?->name ?? '';

		[$representativeConfig, $employeeConfig] = $this->getHcmLinkPlaceholderNameConfigs();

		foreach ([$representativeConfig, $employeeConfig] as $config)
		{
			$configFields = $fields[$config->key]['FIELDS'] ?? [];
			$items = (new HcmLinkPlaceholderCollectorStrategy())->createFromFields($configFields, $config->party);
			
			$config->collection->add(new HcmLinkCompany($hcmLinkCompany->title, $myCompanyTitle, $items));
		}

		return new HcmLinkPlaceholders(
			employee: $employeeConfig->collection,
			representative: $representativeConfig->collection,
		);
	}

	/**
	 * @return HcmLinkPlaceholderNameConfig[]
	 */
	private function getHcmLinkPlaceholderNameConfigs(): array
	{
		return [
			new HcmLinkPlaceholderNameConfig('REPRESENTATIVE', BlockParty::NOT_LAST_PARTY),
			new HcmLinkPlaceholderNameConfig('EMPLOYEE', BlockParty::LAST_PARTY),
		];
	}

	private function getExternalB2eDocumentPlaceholders(): PlaceholderCollection
	{
		$placeholders = new PlaceholderCollection();
		$externalB2eDocumentBlocksMap = [
			BlockCode::B2E_EXTERNAL_ID => 'SIGN_PLACEHOLDER_FACTORY_EXTERNAL_ID_NAME',
			BlockCode::B2E_EXTERNAL_DATE_CREATE => 'SIGN_PLACEHOLDER_FACTORY_EXTERNAL_DATE_NAME',
		];

		foreach ($externalB2eDocumentBlocksMap as $blockCode => $langKey)
		{
			$placeholderName = NameHelper::create(
				$blockCode,
				$this->fieldService->getB2eRegionalFieldTypeByBlockCode($blockCode),
				BlockParty::LAST_PARTY,
			);

			$placeholders->add(new Placeholder(
				Loc::getMessage($langKey) ?? '',
				$placeholderName,
			));
		}

		return $placeholders;
	}
}
