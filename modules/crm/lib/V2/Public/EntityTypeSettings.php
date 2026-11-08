<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public;

use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\Recurring\Manager as RecurringManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\CompanySettings;
use Bitrix\Crm\Settings\ContactSettings;
use Bitrix\Crm\Settings\DealSettings;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

class EntityTypeSettings
{
	/**
	 * Recycle bin of Deal, Lead, Contact and Company is a module setting an admin can switch at any
	 * moment, while {@see self::of} keeps one instance for the whole process. Profiles built here
	 * read that setting on access; an explicitly constructed instance keeps the value it was given.
	 */
	private bool $readRecyclebinFromSettings = false;

	/**
	 * Client flags of a smart process live in its Type config row, which the type settings screen
	 * mutates and saves within the same request, while {@see self::of} keeps one instance for the
	 * whole process. Profiles built by {@see self::createSmartProcessSettings} read the flags on
	 * access; an explicitly constructed instance keeps the values it was given.
	 */
	private bool $readClientFlagsFromType = false;

	/**
	 * @param array<int, EntityType> $parentEntityTypesMap
	 */
	public function __construct(
		private readonly EntityType $entityType,
		private readonly bool $hasCategories = false,
		private readonly bool $isCategoriesSupported = false,
		private readonly bool $hasStages = false,
		private readonly bool $isStagesSupported = false,
		private readonly bool $hasBeginCloseDates = false,
		private readonly bool $hasSource = false,
		private readonly bool $hasProducts = false,
		private readonly bool $hasObservers = false,
		private readonly bool $hasContactBindings = false,
		private readonly bool $hasCompanyBindings = false,
		private readonly bool $hasCompany = false,
		private readonly bool $hasMyCompany = false,
		private readonly bool $hasClientFields = false,
		private readonly bool $hasClientContact = false,
		private readonly bool $hasClientCompany = false,
		private readonly bool $hasMultifields = false,
		private readonly bool $hasCrmTracking = false,
		private readonly bool $hasDocumentGeneration = false,
		private readonly bool $isDocumentGenerationSupported = false,
		private readonly bool $hasRecurring = false,
		private readonly bool $isRecurringSupported = false,
		private readonly bool $hasUseInUserfield = false,
		private readonly bool $hasRecyclebin = false,
		private readonly bool $hasAutomation = false,
		private readonly bool $hasBizProc = false,
		private readonly bool $isBizProcSupported = false,
		private readonly bool $hasPayments = false,
		private readonly bool $hasCounters = false,
		private readonly bool $hasLastActivity = false,
		private readonly bool $isLastActivitySupported = false,
		private ?array $parentEntityTypesMap = null, // see lazy-loading in getParentEntityTypesMap
	)
	{
	}

	public static function of(EntityType $entityType): static
	{
		static $entityTypeSettingsMap = [];

		if (!isset($entityTypeSettingsMap[$entityType->getId()]))
		{
			$entityTypeSettingsMap[$entityType->getId()] = static::create($entityType);
		}

		return $entityTypeSettingsMap[$entityType->getId()];
	}

	protected static function create(EntityType $entityType): static
	{
		return match ($entityType->getId())
		{
			OwnerType::DEAL => static::createDealSettings(),
			OwnerType::LEAD => static::createLeadSettings(),
			OwnerType::CONTACT => static::createContactSettings(),
			OwnerType::COMPANY => static::createCompanySettings(),
			OwnerType::QUOTE => static::createQuoteSettings(),
			// Static smart entities (SmartInvoice/Document/B2eDocument) and custom smart-process
			// IDs (128–191, 1030+ even) all share the Dynamic factory + Type config-row pattern.
			// EntityType::isValid guarantees that nothing else can reach default.
			default => static::createSmartProcessSettings($entityType),
		};
	}

	protected static function createDealSettings(): static
	{
		$settings = new static(
			entityType: EntityType::deal(),
			hasCategories: true,
			isCategoriesSupported: true,
			hasStages: true,
			isStagesSupported: true,
			hasBeginCloseDates: true,
			hasSource: true,
			hasProducts: true,
			hasObservers: true,
			hasContactBindings: true,
			hasCompanyBindings: false,
			hasCompany: true,
			hasMyCompany: true,
			hasClientFields: true,
			hasClientContact: true,
			hasClientCompany: true,
			hasMultifields: false,
			hasCrmTracking: true,
			hasDocumentGeneration: true,
			isDocumentGenerationSupported: DocumentGeneratorManager::getInstance()
				->getCrmOwnerTypeProvider(OwnerType::DEAL, false) !== null,
			hasRecurring: true,
			isRecurringSupported: true,
			hasUseInUserfield: true,
			hasAutomation: true,
			hasBizProc: true,
			isBizProcSupported: true,
			hasPayments: true,
			hasCounters: true,
			hasLastActivity: true,
			isLastActivitySupported: true,
		);
		$settings->readRecyclebinFromSettings = true;

		return $settings;
	}

	protected static function createCompanySettings(): static
	{
		$settings = new static(
			entityType: EntityType::company(),
			hasCategories: true,
			isCategoriesSupported: true,
			hasStages: false,
			isStagesSupported: false,
			hasBeginCloseDates: false,
			hasSource: false,
			hasProducts: false,
			hasObservers: true,
			// V2 binding semantics only: Company does link to Contacts (many-to-many), and REST v3
			// exposes them. This is NOT the legacy "Client field": Factory::isClientEnabled() stays
			// false for Company on purpose (ticket 254142) — do not align one with the other.
			hasContactBindings: true,
			hasCompanyBindings: false,
			hasCompany: false,
			hasMyCompany: false,
			hasClientFields: false,
			hasClientContact: false,
			hasClientCompany: false,
			hasMultifields: true,
			hasCrmTracking: true,
			hasDocumentGeneration: true,
			isDocumentGenerationSupported: DocumentGeneratorManager::getInstance()
				->getCrmOwnerTypeProvider(OwnerType::COMPANY, false) !== null,
			hasRecurring: false,
			isRecurringSupported: false,
			hasUseInUserfield: true,
			hasAutomation: false,
			hasBizProc: true,
			isBizProcSupported: true,
			hasPayments: false,
			hasCounters: true,
			hasLastActivity: true,
			isLastActivitySupported: true,
		);
		$settings->readRecyclebinFromSettings = true;

		return $settings;
	}

	protected static function createContactSettings(): static
	{
		$settings = new static(
			entityType: EntityType::contact(),
			hasCategories: true,
			isCategoriesSupported: true,
			hasStages: false,
			isStagesSupported: false,
			hasBeginCloseDates: false,
			hasSource: true,
			hasProducts: false,
			hasObservers: true,
			// Contact does NOT link to other Contacts. Diverges from $factory->isClientEnabled
			// (which is false for Contact anyway, so values match coincidentally).
			hasContactBindings: false,
			// Contact has the only real many-to-many relationship with Company.
			hasCompanyBindings: true,
			// Contact has its own scalar companyId (HasCompanyTrait in V2).
			// Diverges from $factory->isClientEnabled() which is false for Contact —
			// "Client" abstraction is for items that link to a Contact+Company pair, not for Contact itself.
			hasCompany: true,
			hasMyCompany: false,
			hasClientFields: false,
			hasClientContact: false,
			hasClientCompany: false,
			hasMultifields: true,
			hasCrmTracking: true,
			hasDocumentGeneration: true,
			isDocumentGenerationSupported: DocumentGeneratorManager::getInstance()
				->getCrmOwnerTypeProvider(OwnerType::CONTACT, false) !== null,
			hasRecurring: false,
			isRecurringSupported: false,
			hasUseInUserfield: true,
			hasAutomation: false,
			hasBizProc: true,
			isBizProcSupported: true,
			hasPayments: false,
			hasCounters: true,
			hasLastActivity: true,
			isLastActivitySupported: true,
		);
		$settings->readRecyclebinFromSettings = true;

		return $settings;
	}

	protected static function createQuoteSettings(): static
	{
		return new static(
			entityType: EntityType::quote(),
			hasCategories: false,
			isCategoriesSupported: false,
			hasStages: true,
			isStagesSupported: true,
			hasBeginCloseDates: true,
			hasSource: false,
			hasProducts: true,
			hasObservers: false,
			hasContactBindings: true,
			hasCompanyBindings: false,
			hasCompany: true,
			hasMyCompany: true,
			hasClientFields: false,
			hasClientContact: false,
			hasClientCompany: false,
			hasMultifields: false,
			hasCrmTracking: true,
			hasDocumentGeneration: true,
			isDocumentGenerationSupported: DocumentGeneratorManager::getInstance()
				->getCrmOwnerTypeProvider(OwnerType::QUOTE, false) !== null,
			hasRecurring: false,
			isRecurringSupported: false,
			hasUseInUserfield: false,
			hasRecyclebin: false,
			hasAutomation: true,
			hasBizProc: true,
			isBizProcSupported: true,
			hasPayments: false,
			hasCounters: true,
			hasLastActivity: true,
			isLastActivitySupported: true,
		);
	}

	/**
	 * Settings for smart-process-API-based entities: SmartInvoice, SmartDocument, SmartB2eDocument
	 * and custom smart processes (SPA, ID 128–191 or 1030+ even).
	 *
	 * The bulk of values is type-driven — read from the {@see \Bitrix\Crm\Model\Dynamic\Type} config row.
	 * Static Smart-* subclasses override Dynamic's behavior in a few places; those overrides are
	 * encoded as `match`-based per-id branches below.
	 *
	 * For two values whose source is schema-level (table existence, field existence) — `hasCounters`
	 * and `*LastActivity*` — we delegate to the still-original factory implementations. Those
	 * factory methods are NOT migrated to delegate back, so no recursion is possible.
	 */
	protected static function createSmartProcessSettings(EntityType $entityType): static
	{
		$entityTypeId = $entityType->getId();
		$type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);

		// Client flags are not passed to the constructor: the getters read them from the live Type
		// row on access (see $readClientFlagsFromType), so a flag switched after this snapshot was
		// built is still visible through the cached instance.

		// SmartB2eDocument hardcodes products = false; others read from $type.
		$hasProducts = match ($entityTypeId)
		{
			OwnerType::SMART_B2E_DOCUMENT => false,
			default => $type?->getIsLinkWithProductsEnabled() ?? false,
		};

		// SmartDocument and SmartB2eDocument hardcode automation = true. Dynamic gates it on stages.
		$hasAutomation = match ($entityTypeId)
		{
			OwnerType::SMART_DOCUMENT, OwnerType::SMART_B2E_DOCUMENT => true,
			default =>
				($type?->getIsStagesEnabled() ?? false)
				&& ($type?->getIsAutomationEnabled() ?? false),
		};

		// SmartDocument and SmartB2eDocument hardcode bizproc = false.
		$hasBizProc = match ($entityTypeId)
		{
			OwnerType::SMART_DOCUMENT, OwnerType::SMART_B2E_DOCUMENT => false,
			default => $type?->getIsBizProcEnabled() ?? false,
		};

		// Recurring is gated by an option that tracks whether the schema migration ran.
		$isRecurringSupported = Option::get(
			'crm',
			'~is_recurring_column_alter_success_' . $entityTypeId,
			'Y'
		) !== 'N';

		// SmartDocument/B2eDocument hardcode recurring = false.
		// SmartInvoice drops the $type->getIsRecurringEnabled() requirement (always recurring if supported).
		// Dynamic chains: supported && type-enabled && manager-allowed.
		$hasRecurring = match ($entityTypeId)
		{
			OwnerType::SMART_DOCUMENT, OwnerType::SMART_B2E_DOCUMENT => false,
			OwnerType::SMART_INVOICE =>
				$isRecurringSupported
				&& RecurringManager::isAllowedExpose(RecurringManager::DYNAMIC),
			default =>
				$isRecurringSupported
				&& ($type?->getIsRecurringEnabled() ?? false)
				&& RecurringManager::isAllowedExpose(RecurringManager::DYNAMIC),
		};

		// Tracks whether the schema migration to add LAST_ACTIVITY_TIME / LAST_ACTIVITY_BY columns
		// completed successfully on the dynamic items table for this entity type.
		// See {@see \Bitrix\Crm\Model\Dynamic\TypeTable::wereLastActivityColumnsAddedSuccessfullyOnModuleUpdate}.
		$isLastActivitySupported = Option::get(
			'crm',
			'~last_activity_columns_alter_success_' . $entityTypeId,
			'Y'
		) === 'Y';

		// Rarely a portal lacks the b_crm_dynamic_items_31 table. Enabling counters there sends
		// queries to a missing table and crashes, so SmartInvoice counters stay gated on the table
		// existence (as the legacy SmartInvoice factory did before capability flags moved here).
		$hasCounters = match ($entityTypeId)
		{
			OwnerType::SMART_INVOICE =>
				($type?->getIsCountersEnabled() ?? false)
				&& static::smartInvoiceTableExists(),
			default => $type?->getIsCountersEnabled() ?? false,
		};

		$settings = new static(
			entityType: $entityType,
			hasCategories: $type?->getIsCategoriesEnabled() ?? false,
			isCategoriesSupported: true, // Dynamic default
			hasStages: $type?->getIsStagesEnabled() ?? false,
			isStagesSupported: true, // base Factory default; Dynamic doesn't override
			hasBeginCloseDates: $type?->getIsBeginCloseDatesEnabled() ?? false,
			hasSource: $type?->getIsSourceEnabled() ?? false,
			hasProducts: $hasProducts,
			hasObservers: $type?->getIsObserversEnabled() ?? false,
			hasCompanyBindings: false, // smart-* are not Contact
			hasMyCompany: $type?->getIsMycompanyEnabled() ?? false,
			hasMultifields: false, // Dynamic doesn't override; base default false
			hasCrmTracking: false, // Dynamic::isCrmTrackingEnabled is final → false
			hasDocumentGeneration: $type?->getIsDocumentsEnabled() ?? false,
			isDocumentGenerationSupported: DocumentGeneratorManager::getInstance()
				->getCrmOwnerTypeProvider($entityTypeId, false) !== null,
			hasRecurring: $hasRecurring,
			isRecurringSupported: $isRecurringSupported,
			hasUseInUserfield: $type?->getIsUseInUserfieldEnabled() ?? false,
			hasRecyclebin: $type?->getIsRecyclebinEnabled() ?? false,
			hasAutomation: $hasAutomation,
			hasBizProc: $hasBizProc,
			isBizProcSupported: true, // Dynamic override
			hasPayments: $entityTypeId === OwnerType::SMART_INVOICE,
			hasCounters: $hasCounters,
			hasLastActivity: $isLastActivitySupported,
			isLastActivitySupported: $isLastActivitySupported,
		);
		$settings->readClientFlagsFromType = true;

		return $settings;
	}

	private static function smartInvoiceTableExists(): bool
	{
		$cache = Application::getInstance()->getManagedCache();
		$cacheKey = 'crm_check__b_crm_dynamic_items_31__table';

		if ($cache->read(3600 * 24 * 7, $cacheKey))
		{
			return (bool)$cache->get($cacheKey);
		}

		$hasTable = Application::getConnection()->isTableExists('b_crm_dynamic_items_31');
		$cache->set($cacheKey, $hasTable);

		return $hasTable;
	}

	protected static function createLeadSettings(): static
	{
		$settings = new static(
			entityType: EntityType::lead(),
			hasCategories: false,
			isCategoriesSupported: false,
			hasStages: true,
			isStagesSupported: true,
			hasBeginCloseDates: false,
			hasSource: true,
			hasProducts: true,
			hasObservers: true,
			hasContactBindings: true,
			hasCompanyBindings: false,
			hasCompany: true,
			hasMyCompany: false,
			hasClientFields: false,
			hasClientContact: false,
			hasClientCompany: false,
			hasMultifields: true,
			hasCrmTracking: true,
			hasDocumentGeneration: true,
			isDocumentGenerationSupported: DocumentGeneratorManager::getInstance()
				->getCrmOwnerTypeProvider(OwnerType::LEAD, false) !== null,
			hasRecurring: false,
			isRecurringSupported: false,
			hasUseInUserfield: true,
			hasAutomation: true,
			hasBizProc: true,
			isBizProcSupported: true,
			hasPayments: false,
			hasCounters: true,
			hasLastActivity: true,
			isLastActivitySupported: true,
		);
		$settings->readRecyclebinFromSettings = true;

		return $settings;
	}

	public function getEntityType(): EntityType
	{
		return $this->entityType;
	}

	public function hasCategories(): bool
	{
		return $this->hasCategories;
	}

	public function isCategoriesSupported(): bool
	{
		return $this->isCategoriesSupported;
	}

	public function hasStages(): bool
	{
		return $this->hasStages;
	}

	public function isStagesSupported(): bool
	{
		return $this->isStagesSupported;
	}

	public function hasBeginCloseDates(): bool
	{
		return $this->hasBeginCloseDates;
	}

	public function hasSource(): bool
	{
		return $this->hasSource;
	}

	public function hasProducts(): bool
	{
		return $this->hasProducts;
	}

	public function hasObservers(): bool
	{
		return $this->hasObservers;
	}

	public function hasClient(): bool
	{
		return $this->hasContactBindings() || $this->hasCompany();
	}

	public function hasContactBindings(): bool
	{
		if ($this->readClientFlagsFromType)
		{
			// hasContactBindings == hasCompany == "Client abstraction enabled" in legacy semantics.
			return $this->isClientEnabledInType();
		}

		return $this->hasContactBindings;
	}

	public function hasCompanyBindings(): bool
	{
		return $this->hasCompanyBindings;
	}

	public function hasCompany(): bool
	{
		if ($this->readClientFlagsFromType)
		{
			return $this->isClientEnabledInType();
		}

		return $this->hasCompany;
	}

	public function hasMyCompany(): bool
	{
		return $this->hasMyCompany;
	}

	public function hasClientFields(): bool
	{
		if ($this->readClientFlagsFromType)
		{
			return $this->hasClientContact() || $this->hasClientCompany();
		}

		return $this->hasClientFields;
	}

	public function hasClientContact(): bool
	{
		if ($this->readClientFlagsFromType)
		{
			// Dynamic::isClientContactEnabled id-based branch: SmartDocument → always true.
			return $this->entityType->getId() === OwnerType::SMART_DOCUMENT
				|| $this->hasClientCompany();
		}

		return $this->hasClientContact;
	}

	public function hasClientCompany(): bool
	{
		if ($this->readClientFlagsFromType)
		{
			// Dynamic::isClientCompanyEnabled id-based branches: SmartInvoice → always true,
			// custom SmartProcess (128+) → follows isClientEnabled.
			$entityTypeId = $this->entityType->getId();

			return $entityTypeId === OwnerType::SMART_INVOICE
				|| ($this->isClientEnabledInType() && EntityType::isPossibleSmartProcessTypeId($entityTypeId));
		}

		return $this->hasClientCompany;
	}

	private function isClientEnabledInType(): bool
	{
		$type = Container::getInstance()->getTypeByEntityTypeId($this->entityType->getId());

		return $type?->getIsClientEnabled() ?? false;
	}

	public function hasMultifields(): bool
	{
		return $this->hasMultifields;
	}

	public function hasCrmTracking(): bool
	{
		return $this->hasCrmTracking;
	}

	public function hasDocumentGeneration(): bool
	{
		return $this->hasDocumentGeneration;
	}

	public function isDocumentGenerationSupported(): bool
	{
		return $this->isDocumentGenerationSupported;
	}

	public function hasRecurring(): bool
	{
		return $this->hasRecurring;
	}

	public function isRecurringSupported(): bool
	{
		return $this->isRecurringSupported;
	}

	public function hasUseInUserfield(): bool
	{
		return $this->hasUseInUserfield;
	}

	public function hasRecyclebin(): bool
	{
		if ($this->readRecyclebinFromSettings)
		{
			return match ($this->entityType->getId())
			{
				OwnerType::DEAL => DealSettings::getCurrent()->isRecycleBinEnabled(),
				OwnerType::LEAD => LeadSettings::getCurrent()->isRecycleBinEnabled(),
				OwnerType::CONTACT => ContactSettings::getCurrent()->isRecycleBinEnabled(),
				OwnerType::COMPANY => CompanySettings::getCurrent()->isRecycleBinEnabled(),
				default => $this->hasRecyclebin,
			};
		}

		return $this->hasRecyclebin;
	}

	public function hasAutomation(): bool
	{
		return $this->hasAutomation;
	}

	public function hasBizProc(): bool
	{
		return $this->hasBizProc;
	}

	public function isBizProcSupported(): bool
	{
		return $this->isBizProcSupported;
	}

	public function hasPayments(): bool
	{
		return $this->hasPayments;
	}

	public function hasCounters(): bool
	{
		return $this->hasCounters;
	}

	public function hasLastActivity(): bool
	{
		return $this->hasLastActivity;
	}

	public function isLastActivitySupported(): bool
	{
		return $this->isLastActivitySupported;
	}

	/**
	 * Parent fields of non-Item entity types (ORDER and alike) are skipped: they have no EntityType contract.
	 *
	 * @return array<int, EntityType>
	 */
	public function getParentEntityTypesMap(): array
	{
		if (!isset($this->parentEntityTypesMap))
		{
			$fieldsInfo =
				Container::getInstance()
					->getParentFieldManager()
					->getParentFieldsInfo($this->entityType->getId())
			;
			$result = [];
			foreach ($fieldsInfo as $fieldInfo)
			{
				if (!is_array($fieldInfo) || !is_array($fieldInfo['SETTINGS'] ?? null))
				{
					continue;
				}

				$parentEntityTypeId = (int)($fieldInfo['SETTINGS']['parentEntityTypeId'] ?? 0);
				if (!EntityType::isValid($parentEntityTypeId))
				{
					continue;
				}

				$result[$parentEntityTypeId] = EntityType::fromId($parentEntityTypeId);
			}

			$this->parentEntityTypesMap = $result;
		}

		return $this->parentEntityTypesMap;
	}
}
