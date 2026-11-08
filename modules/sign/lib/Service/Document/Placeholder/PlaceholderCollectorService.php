<?php

namespace Bitrix\Sign\Service\Document\Placeholder;

use Bitrix\Main\Loader;
use Bitrix\Sign\Config;
use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Helper\Field\UserFieldCodeHelper;
use Bitrix\Sign\Item\Document\Placeholder\HcmLinkCompany;
use Bitrix\Sign\Item\Document\Placeholder\HcmLinkCompanyCollection;
use Bitrix\Sign\Item\Document\Placeholder\HcmLinkPlaceholderNameConfig;
use Bitrix\Sign\Item\Document\Placeholder\HcmLinkPlaceholders;
use Bitrix\Sign\Item\Document\Placeholder\Placeholder;
use Bitrix\Sign\Item\Document\Placeholder\PlaceholderCollection;
use Bitrix\Sign\Item\Document\Placeholder\PlaceholderNameConfig;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Sign\Service\Document\FieldAccessService;
use Bitrix\Sign\Service\Document\FieldService;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\CrmReferencePlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\EmployeeDynamicPlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\HcmLinkPlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Document\Placeholder\Strategy\UserFieldsPlaceholderCollectorStrategy;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\MyCompanyService;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkFieldService;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkService;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasRoleResolver;
use Bitrix\Sign\Service\Placeholder\FieldAlias\FieldAliasService;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Service\Placeholder\Restriction\PlaceholderRestrictionService;
use Bitrix\Sign\Service\Placeholder\Restriction\RestrictionPolicy;
use Bitrix\Sign\Service\Placeholder\Restriction\ScenarioContext;
use Bitrix\Sign\Service\Providers\InfoProvider;
use Bitrix\Sign\Service\Providers\LegalInfoProvider;
use Bitrix\Sign\Service\Providers\MemberDynamicFieldInfoProvider;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\BlockParty;
use Bitrix\Sign\Type\Field\FrontFieldCategory;
use Bitrix\Sign\Ui\PlaceholderGrid\SectionBuilder;
use Psr\Log\LoggerInterface;

class PlaceholderCollectorService
{
	private const PRESET_ID = 1;

	/**
	 * The list only offers codes, so a scenario axis it cannot resolve yet restricts nothing here;
	 * the warning before sending is the one that reads the same axes strictly.
	 */
	private const LIST_RESTRICTION_POLICY = RestrictionPolicy::PERMISSIVE;

	private readonly LoggerInterface $logger;
	private readonly Config\Storage $config;
	private readonly FieldAccessService $fieldAccessService;
	private readonly AliasRoleResolver $aliasRoleResolver;
	private readonly LegalInfoProvider $legalInfoProvider;
	private readonly MemberDynamicFieldInfoProvider $memberDynamicFieldProvider;

	private bool $verifiedAliasFilterDisabled = false;
	private bool $hcmLinkCollectionFullyHidden = false;

	public function __construct(
		private readonly FieldService $fieldService,
		private readonly MyCompanyService $myCompanyService,
		private readonly HcmLinkFieldService $hcmLinkFieldService,
		private readonly HcmLinkService $hcmLinkService,
		private readonly PlaceholderCacheService $placeholderCacheService,
		private readonly SectionBuilder $sectionBuilder,
		private readonly FieldAliasService $fieldAliasService,
		private readonly PlaceholderRestrictionService $restrictionService,
		?LoggerInterface $logger = null,
		?Config\Storage $config = null,
		?FieldAccessService $fieldAccessService = null,
		?AliasRoleResolver $aliasRoleResolver = null,
		?LegalInfoProvider $legalInfoProvider = null,
		?MemberDynamicFieldInfoProvider $memberDynamicFieldProvider = null,
	)
	{
		$container = Container::instance();
		$this->logger = $logger ?? $container->getLogger('Service');
		$this->config = $config ?? Config\Storage::instance();
		$this->fieldAccessService = $fieldAccessService ?? $container->getDocumentFieldAccessService();
		$this->aliasRoleResolver = $aliasRoleResolver ?? $container->getAliasRoleResolver();
		$this->legalInfoProvider = $legalInfoProvider ?? $container->getLegalInfoProvider();
		$this->memberDynamicFieldProvider = $memberDynamicFieldProvider
			?? $container->getMemberDynamicFieldProvider()
		;
	}

	/**
	 * @param bool $clearCache Skips reading the cache, so the list is rebuilt for this request only.
	 * @param ScenarioContext|null $scenario Restricts the list to the scenario of the document; null keeps the full list.
	 * @param bool $withDeletableMarks marks of the deletable fields (NORMATIVE ALG-B02) belong to the
	 *                                 answer of the list; they cost the field maps of the permitted
	 *                                 categories, so a caller that reads the codes of the fields only
	 *                                 asks for the list without them.
	 */
	public function loadPlaceholdersByUserId(
		int $userId,
		bool $clearCache = false,
		?ScenarioContext $scenario = null,
		bool $withDeletableMarks = true,
	): Result
	{
		$loadPlaceholdersResult = new Result();

		$restrictionScenario = $this->resolveRestrictionScenario($scenario);
		$cacheSignature = $restrictionScenario === null
			? PlaceholderCacheService::NO_SCENARIO_SIGNATURE
			: $this->restrictionService->getFilteredListSignature($restrictionScenario, self::LIST_RESTRICTION_POLICY)
		;

		if (!$clearCache)
		{
			$placeholdersFromCache = $this->placeholderCacheService->getPlaceholderList($cacheSignature);
			// the entry goes on to markDeletableFields(array), so a non-array rebuilds the list
			if (is_array($placeholdersFromCache))
			{
				return $loadPlaceholdersResult->setData(
					$withDeletableMarks
						? $this->markDeletableFields($placeholdersFromCache, $userId)
						: $placeholdersFromCache,
				);
			}
		}

		$this->verifiedAliasFilterDisabled = $this->config->isPlaceholderVerifiedAliasFilterDisabled();

		$fieldsDataResult = $this->fieldService->loadByUserId($userId, $this->getDocumentFieldOptions());
		if (!$fieldsDataResult->isSuccess())
		{
			return $loadPlaceholdersResult->addErrors($fieldsDataResult->getErrors());
		}

		$fieldsData = $fieldsDataResult->getData();
		$placeholders = $this->createPlaceholdersFromFields($fieldsData);
		$placeholders['externalB2eDocument'] = $this->areRegionalFieldsAvailable()
			? $this->getExternalB2eDocumentPlaceholders()
			: new PlaceholderCollection()
		;

		if ($restrictionScenario !== null)
		{
			$placeholders = $this->restrictionService->filterSupported(
				$placeholders,
				$restrictionScenario,
				self::LIST_RESTRICTION_POLICY,
				$this->collectRawFieldTypes($fieldsData),
			);
		}

		$placeholders = $this->convertAllPlaceholdersToAliases($placeholders);

		$sections = $this->sectionBuilder->buildSections($placeholders);
		$this->placeholderCacheService->setPlaceholderList($cacheSignature, $sections);

		return $loadPlaceholdersResult->setData(
			$withDeletableMarks ? $this->markDeletableFields($sections, $userId) : $sections,
		);
	}

	public function loadPlaceholdersByHcmLinkCompanyId(
		int $hcmLinkCompanyId,
		?ScenarioContext $scenario = null,
	): Result
	{
		$loadPlaceholdersResult = new Result();
		$restrictionScenario = $this->resolveRestrictionScenario($scenario);
		$cacheSignature = $restrictionScenario === null
			? PlaceholderCacheService::NO_SCENARIO_SIGNATURE
			: $this->restrictionService->getFilteredListSignature($restrictionScenario, self::LIST_RESTRICTION_POLICY)
		;
		$placeholdersFromCache = $restrictionScenario === null
			? $this->placeholderCacheService->getPlaceholderListByHcmLinkCompanyId($hcmLinkCompanyId)
			: $this->placeholderCacheService->getPlaceholderListByHcmLinkCompanyId($hcmLinkCompanyId, $cacheSignature)
		;
		if ($placeholdersFromCache !== null)
		{
			return $loadPlaceholdersResult->setData($placeholdersFromCache);
		}

		$hcmLinkPlaceholders = $this->createHcmLinkPlaceholders($hcmLinkCompanyId);
		if (!$hcmLinkPlaceholders)
		{
			return $loadPlaceholdersResult;
		}

		$this->verifiedAliasFilterDisabled = $this->config->isPlaceholderVerifiedAliasFilterDisabled();
		$this->hcmLinkCollectionFullyHidden = false;

		$baseContext = AliasContext::empty()->with(hcmLinkCompanyId: $hcmLinkCompanyId);

		$rawFieldNames = $this->collectHcmLinkRawFieldNames($hcmLinkPlaceholders);
		$this->fieldAliasService->preloadForFieldNames($rawFieldNames, $baseContext);

		if ($restrictionScenario !== null)
		{
			$hcmLinkPlaceholders = $this->filterHcmLinkPlaceholders($hcmLinkPlaceholders, $restrictionScenario);
		}

		$hcmLinkPlaceholders = $this->convertHcmLinkPlaceholdersToAliases($hcmLinkPlaceholders, $baseContext);

		$sections = $this->sectionBuilder->buildHcmLinkSections($hcmLinkPlaceholders);
		$loadPlaceholdersResult->setData($sections);

		if (!$this->hcmLinkCollectionFullyHidden)
		{
			if ($restrictionScenario === null)
			{
				$this->placeholderCacheService->setPlaceholderListByHcmLinkCompanyId($hcmLinkCompanyId, $sections);
			}
			else
			{
				$this->placeholderCacheService->setPlaceholderListByHcmLinkCompanyId(
					$hcmLinkCompanyId,
					$sections,
					$cacheSignature,
				);
			}
		}

		return $loadPlaceholdersResult;
	}

	private function filterHcmLinkPlaceholders(
		HcmLinkPlaceholders $placeholders,
		ScenarioContext $scenario,
	): HcmLinkPlaceholders
	{
		return new HcmLinkPlaceholders(
			employee: $this->filterHcmLinkCompanyCollection($placeholders->employee, $scenario),
			representative: $this->filterHcmLinkCompanyCollection($placeholders->representative, $scenario),
		);
	}

	private function filterHcmLinkCompanyCollection(
		HcmLinkCompanyCollection $companies,
		ScenarioContext $scenario,
	): HcmLinkCompanyCollection
	{
		$result = new HcmLinkCompanyCollection();
		foreach ($companies as $company)
		{
			$filtered = $this->restrictionService->filterSupported(
				['items' => $company->items],
				$scenario,
				self::LIST_RESTRICTION_POLICY,
			);
			$result->add(new HcmLinkCompany(
				$company->hcmLinkTitle,
				$company->myCompanyTitle,
				$filtered['items'],
			));
		}

		return $result;
	}

	/**
	 * Whether the regional external fields are offered at all.
	 *
	 * The decision belongs to the restriction config and depends on the portal region alone, so it
	 * holds for a scenario-less list as well; it is not the scenario filter, which cuts the built map.
	 */
	private function areRegionalFieldsAvailable(): bool
	{
		return $this->restrictionService->isRegionalFieldAvailable(ScenarioContext::fromAxes());
	}

	/**
	 * Single decision point for scenario filtering: it keeps the applied filter and the cache
	 * signature of the stored list from ever drifting apart.
	 */
	private function resolveRestrictionScenario(?ScenarioContext $scenario): ?ScenarioContext
	{
		if ($scenario === null || !$this->config->isPlaceholderRestrictionEnabled())
		{
			return null;
		}

		return $scenario;
	}

	/**
	 * @return string[]
	 */
	private function collectHcmLinkRawFieldNames(HcmLinkPlaceholders $hcmLinkPlaceholders): array
	{
		$rawFieldNames = [];
		foreach ([$hcmLinkPlaceholders->employee, $hcmLinkPlaceholders->representative] as $companyCollection)
		{
			foreach ($companyCollection as $company)
			{
				foreach ($company->items as $placeholder)
				{
					$rawFieldNames[] = $placeholder->value;
				}
			}
		}

		return $rawFieldNames;
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
	 * Raw types of the collected fields, by the code their placeholder names carry.
	 *
	 * Every source names its codes in its own namespace, so the map of each strategy that owns one
	 * merges into a single map. The CRM fields need no entry: they are offered on the party of the
	 * company representative, and no rule judges the raw type of that party.
	 *
	 * @return array<string, string>
	 */
	private function collectRawFieldTypes(array $fieldsData): array
	{
		$fields = $fieldsData['fields'] ?? [];

		return UserFieldsPlaceholderCollectorStrategy::collectRawFieldTypes(
			$fields[FrontFieldCategory::PROFILE->value]['FIELDS'] ?? [],
		) + EmployeeDynamicPlaceholderCollectorStrategy::collectRawFieldTypes(
			$fields[FrontFieldCategory::DYNAMIC_MEMBER->value]['FIELDS'] ?? [],
		);
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
		$baseContext = AliasContext::empty();

		$result = [];
		foreach ($placeholdersMap as $sectionName => $collection)
		{
			$converted = $this->convertPlaceholderCollection($collection, $baseContext);
			if (!$this->verifiedAliasFilterDisabled && $this->isSourceCollectionFullyHidden($collection, $converted))
			{
				$this->logFullyHiddenCollection(['section' => $sectionName]);
			}

			$result[$sectionName] = $converted;
		}

		return $result;
	}

	/**
	 * Converts HcmLink placeholder collections to aliases
	 * This method traverses the structure and applies alias conversion to all PlaceholderCollections
	 * @param HcmLinkPlaceholders $hcmLinkPlaceholders HcmLink placeholders with full field names
	 * @param AliasContext $baseContext Base context of the HcmLink path (carries hcmLinkCompanyId)
	 * @return HcmLinkPlaceholders New HcmLinkPlaceholders with all placeholders converted to aliases
	 */
	private function convertHcmLinkPlaceholdersToAliases(
		HcmLinkPlaceholders $hcmLinkPlaceholders,
		AliasContext $baseContext,
	): HcmLinkPlaceholders
	{
		return new HcmLinkPlaceholders(
			employee: $this->convertHcmLinkCompanyCollection($hcmLinkPlaceholders->employee, $baseContext),
			representative: $this->convertHcmLinkCompanyCollection($hcmLinkPlaceholders->representative, $baseContext),
		);
	}

	/**
	 * Converts a single HcmLinkCompanyCollection
	 * Helper method that processes all companies in the collection
	 * @param HcmLinkCompanyCollection $companyCollection Collection to convert
	 * @param AliasContext $baseContext Base context of the HcmLink path (carries hcmLinkCompanyId)
	 * @return HcmLinkCompanyCollection Converted collection
	 */
	private function convertHcmLinkCompanyCollection(
		HcmLinkCompanyCollection $companyCollection,
		AliasContext $baseContext,
	): HcmLinkCompanyCollection
	{
		$result = new HcmLinkCompanyCollection();

		foreach ($companyCollection as $company)
		{
			$convertedItems = $this->convertPlaceholderCollection($company->items, $baseContext);

			if (!$this->verifiedAliasFilterDisabled && $this->isSourceCollectionFullyHidden($company->items, $convertedItems))
			{
				$this->hcmLinkCollectionFullyHidden = true;
				$this->logFullyHiddenCollection([
					'hcmLinkTitle' => $company->hcmLinkTitle,
					'myCompanyTitle' => $company->myCompanyTitle,
				]);
			}

			$result->add(new HcmLinkCompany(
				$company->hcmLinkTitle,
				$company->myCompanyTitle,
				$convertedItems,
			));
		}

		return $result;
	}

	/**
	 * Converts a single PlaceholderCollection to aliases.
	 * When the verified alias filter is enabled, fields without a verified reversible alias are skipped.
	 * @param PlaceholderCollection $collection Collection to convert
	 * @param AliasContext $baseContext Base context; party is derived per-placeholder from the field name
	 * @return PlaceholderCollection Converted collection
	 */
	private function convertPlaceholderCollection(
		PlaceholderCollection $collection,
		AliasContext $baseContext,
	): PlaceholderCollection
	{
		$result = new PlaceholderCollection();

		foreach ($collection as $placeholder)
		{
			$parsed = NameHelper::parse($placeholder->value);
			$party = $parsed['party'] ?? BlockParty::LAST_PARTY;
			$context = $baseContext->withParty($party);

			if ($this->verifiedAliasFilterDisabled)
			{
				$alias = $this->fieldAliasService->toAlias($placeholder->value, $context);
				$result->add(new Placeholder(
					$placeholder->name,
					$alias ?? $placeholder->value,
					$placeholder->type,
				));

				continue;
			}

			$alias = $this->fieldAliasService->toVerifiedAlias($placeholder->value, $context);
			if ($alias === null)
			{
				continue;
			}

			$result->add(new Placeholder(
				$placeholder->name,
				$alias,
				$placeholder->type,
			));
		}

		return $result;
	}

	private function isSourceCollectionFullyHidden(
		PlaceholderCollection $source,
		PlaceholderCollection $converted,
	): bool
	{
		return !$source->isEmpty() && $converted->isEmpty();
	}

	/**
	 * @param array<string, string> $context
	 */
	private function logFullyHiddenCollection(array $context): void
	{
		$this->logger->warning(
			'Placeholder collection fully hidden by verified alias filter',
			$context,
		);
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

		// the identifier arrives from the client, so a company of no such identifier answers empty
		$hcmLinkCompany = $this->hcmLinkService->getById($hcmLinkCompanyId);
		if ($hcmLinkCompany === null)
		{
			return null;
		}

		$fields = $this->hcmLinkFieldService->getFieldsForSelector($hcmLinkCompanyId);

		$myCompanies = $this->myCompanyService->listWithTaxIds();
		$myCompany = $myCompanies->findById($hcmLinkCompany->myCompanyId);
		$myCompanyTitle = $myCompany?->name ?? '';

		[$representativeConfig, $employeeConfig] = $this->getHcmLinkPlaceholderNameConfigs();

		foreach ([$representativeConfig, $employeeConfig] as $config)
		{
			$configFields = $fields[$config->key]['FIELDS'] ?? [];
			$items = (new HcmLinkPlaceholderCollectorStrategy($this->hcmLinkFieldService))
				->createFromFields($configFields, $config->party)
			;
			
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
			$fieldType = $this->fieldService->getB2eRegionalFieldTypeByBlockCode($blockCode);
			$placeholderName = NameHelper::create(
				$blockCode,
				$fieldType,
				BlockParty::LAST_PARTY,
			);

			$placeholders->add(new Placeholder(
				Loc::getMessage($langKey) ?? '',
				$placeholderName,
				$fieldType,
			));
		}

		return $placeholders;
	}

	/**
	 * Adds `fieldId` and `category` to the items the user may delete. The mark is personal and goes to
	 * the answer only: the portal cache is shared and carries no user in its key.
	 *
	 * @param array<int, array> $sections sections of the fields list, possibly with subsections
	 *
	 * @return array<int, array>
	 */
	private function markDeletableFields(array $sections, int $userId): array
	{
		$fieldMapsByCategory = $this->getDeletableFieldMapsByCategory($userId);
		if ($fieldMapsByCategory === [])
		{
			return $sections;
		}

		return $this->markDeletableItemsOfSections($sections, $fieldMapsByCategory);
	}

	/**
	 * @return array<string, array<string, int>> field identifiers by name of the categories the user
	 *                                           may delete from
	 */
	private function getDeletableFieldMapsByCategory(int $userId): array
	{
		$fieldMaps = [];
		foreach ($this->fieldAccessService->getDeleteByCategoryPermissions($userId) as $category => $isAllowed)
		{
			if (!$isAllowed || !$this->isDeleteOfCategoryAvailable($category))
			{
				continue;
			}

			$provider = $this->getFieldProviderByCategory($category);
			if ($provider !== null)
			{
				$fieldMaps[$category] = $provider->getFieldIdsByName();
			}
		}

		return $fieldMaps;
	}

	/**
	 * The gates the action of the deletion checks again
	 * (Controllers\V1\B2e\Document\Placeholder::isDeleteOfCategoryAllowed()): a category the action
	 * refuses must carry no mark at all, otherwise the panel offers an irreversible operation that
	 * always ends with an error.
	 */
	private function isDeleteOfCategoryAvailable(string $category): bool
	{
		return $category !== FrontFieldCategory::DYNAMIC_MEMBER->value
			|| Config\Feature::instance()->isDocumentTemplatesAvailable()
		;
	}

	private function getFieldProviderByCategory(string $category): ?InfoProvider
	{
		return match ($category)
		{
			FrontFieldCategory::PROFILE->value => $this->legalInfoProvider,
			FrontFieldCategory::DYNAMIC_MEMBER->value => $this->memberDynamicFieldProvider,
			default => null,
		};
	}

	/**
	 * @param array<int, array> $sections
	 * @param array<string, array<string, int>> $fieldMapsByCategory
	 *
	 * @return array<int, array>
	 */
	private function markDeletableItemsOfSections(array $sections, array $fieldMapsByCategory): array
	{
		foreach ($sections as &$section)
		{
			foreach (($section['items'] ?? []) as $index => $item)
			{
				$section['items'][$index] = $this->markDeletableItem($item, $fieldMapsByCategory);
			}

			if (isset($section['subsections']))
			{
				$section['subsections'] = $this->markDeletableItemsOfSections(
					$section['subsections'],
					$fieldMapsByCategory,
				);
			}
		}
		unset($section);

		return $sections;
	}

	/**
	 * A profile field stands in the answer twice, under the representative and under the personal data
	 * of the employee: both codes lead to one user field, so both items get the same mark. Default
	 * legal fields are never deletable; the virtual full name has no user field and is absent from the map.
	 *
	 * @param array<string, mixed> $item
	 * @param array<string, array<string, int>> $fieldMapsByCategory
	 *
	 * @return array<string, mixed>
	 */
	private function markDeletableItem(array $item, array $fieldMapsByCategory): array
	{
		$fieldCode = $item['value'] ?? null;
		if (!is_string($fieldCode) || $fieldCode === '')
		{
			return $item;
		}

		$fieldName = $this->resolveUserFieldName($fieldCode);
		foreach ($fieldMapsByCategory as $category => $fieldMap)
		{
			if (!isset($fieldMap[$fieldName]))
			{
				continue;
			}

			if (
				$category === FrontFieldCategory::PROFILE->value
				&& in_array($fieldName, LegalInfoProvider::LEGAL_USER_FIELD_DEFAULT, true)
			)
			{
				break;
			}

			$item['fieldId'] = $fieldMap[$fieldName];
			$item['category'] = $category;

			break;
		}

		return $item;
	}

	/**
	 * Resolves backwards, from the code the panel shows to the name of the user field: a verified alias
	 * carries a shortcut of the field type, so building the code forward from the field map would
	 * repeat the type heuristic and could match nothing. A legacy raw internal name resolves as is.
	 */
	private function resolveUserFieldName(string $fieldCode): string
	{
		$context = $this->aliasRoleResolver->resolveContextByAlias(AliasContext::empty(), $fieldCode);
		$internalName = $this->fieldAliasService->toFieldName($fieldCode, $context) ?? $fieldCode;
		$fieldName = NameHelper::parse($internalName)['fieldCode'];

		return UserFieldCodeHelper::removePrefix($fieldName);
	}
}
