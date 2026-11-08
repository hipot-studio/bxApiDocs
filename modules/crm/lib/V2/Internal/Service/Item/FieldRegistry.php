<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ORM\Entity;

/**
 * Per-EntityType registry of field metadata for CRM Item entities.
 * Maps V2 camelCase field names to ORM/DB column names.
 *
 * Capability decisions (does this entity type have stages? categories? products?) come from
 * {@see EntityTypeSettings} — single source of truth, fed from the legacy Factory.
 *
 * Usage:
 *   $registry = FieldRegistry::getInstance(EntityType::deal());
 *   $registry->getFields(); // [camelName => FieldDescriptor]
 *   $registry->getField('stageId')?->ormName; // 'STAGE_ID'
 *
 * @internal
 */
class FieldRegistry
{
	/**
	 * Relation-backed field types as a set keyed by enum value: membership is asked once per field
	 * on every registry build, so a keyed lookup beats a linear scan over a rebuilt array.
	 */
	private const RELATION_FIELD_TYPES = [
		FieldType::MultifieldCollection->value => true,
		FieldType::ProductRowCollection->value => true,
		FieldType::ContactBindingCollection->value => true,
		FieldType::CompanyBindingCollection->value => true,
		FieldType::ObserverCollection->value => true,
		FieldType::Utm->value => true,
	];

	/** @var array<int, self> */
	private static array $instances = [];

	private readonly EntityType $entityType;

	/** @var array<string, FieldDescriptor>|null camelName => FieldDescriptor (lazy) */
	private ?array $fields = null;

	/** @var array<string, FieldDescriptor>|null ormName => FieldDescriptor, scalars only (lazy) */
	private ?array $scalarFieldsByOrmName = null;

	/** @var array<string, string>|null entity-specific field name => common legacy field name (lazy) */
	private ?array $legacyFieldNamesByEntityFieldName = null;

	public static function getInstance(EntityType $entityType): self
	{
		$id = $entityType->getId();
		if (!isset(self::$instances[$id]))
		{
			self::$instances[$id] = new self($entityType);
		}

		return self::$instances[$id];
	}

	private function __construct(EntityType $entityType)
	{
		$this->entityType = $entityType;
	}

	public function getEntityType(): EntityType
	{
		return $this->entityType;
	}

	public function getField(string $camelName): ?FieldDescriptor
	{
		return $this->getFields()[$camelName] ?? null;
	}

	/** @return array<string, FieldDescriptor> camelName => FieldDescriptor */
	public function getFields(): array
	{
		if ($this->fields === null)
		{
			$this->fields = $this->buildFields();
		}

		return $this->fields;
	}

	/**
	 * Reverse index of the scalar fields: storage column -> descriptor. Lets a reader walk the
	 * columns it actually asked for instead of the whole field set of the type
	 * ({@see Mapper\ItemFieldMapper::syncFromOrm()}).
	 *
	 * Relation-backed fields are left out on purpose: `phones`, `emails`, `webs` and `ims` all sit
	 * on the single `FM` column, so keying them by column would collapse four fields into one. They
	 * are loaded by dedicated Loaders anyway, never by the scalar copy.
	 *
	 * Built once alongside {@see getFields()} and memoized the same way - the index depends on the
	 * entity type only, never on the reader.
	 *
	 * @return array<string, FieldDescriptor> ormName => FieldDescriptor
	 */
	public function getScalarFieldsByOrmName(): array
	{
		if ($this->scalarFieldsByOrmName === null)
		{
			$index = [];
			foreach ($this->getFields() as $descriptor)
			{
				if (!self::isRelationFieldType($descriptor->type))
				{
					$index[$descriptor->ormName] = $descriptor;
				}
			}

			$this->scalarFieldsByOrmName = $index;
		}

		return $this->scalarFieldsByOrmName;
	}

	public function getLegacyFieldName(string $entityFieldName): string
	{
		if ($this->legacyFieldNamesByEntityFieldName === null)
		{
			$factory = Container::getInstance()->getFactory($this->entityType->getId());
			$this->legacyFieldNamesByEntityFieldName = $factory === null
				? []
				: array_flip($factory->getFieldsMap());
		}

		return $this->legacyFieldNamesByEntityFieldName[$entityFieldName] ?? $entityFieldName;
	}

	// --- Field-set builders (private) ---

	/** @return array<string, FieldDescriptor> */
	private function buildFields(): array
	{
		$settings = EntityTypeSettings::of($this->entityType);

		$fields = $this->getCommonFields();

		if ($settings->isLastActivitySupported())
		{
			$fields = array_merge($fields, $this->getLastActivityFields());
		}
		if ($settings->isStagesSupported())
		{
			$fields = array_merge($fields, $this->getStageFields());
		}
		if ($settings->isCategoriesSupported())
		{
			$fields = array_merge($fields, $this->getCategoryFields());
		}
		if ($settings->hasProducts())
		{
			$fields = array_merge($fields, $this->getProductFields());
		}
		if ($settings->hasContactBindings())
		{
			$fields = array_merge($fields, $this->getContactBindingFields());
		}
		if ($settings->hasCompanyBindings())
		{
			$fields = array_merge($fields, $this->getCompanyBindingFields());
		}
		if ($settings->hasCompany())
		{
			$fields = array_merge($fields, $this->getCompanyFields());
		}
		if ($settings->hasMultifields())
		{
			$fields = array_merge($fields, $this->getMultifieldFields());
		}
		if ($settings->hasMyCompany())
		{
			$fields = array_merge($fields, $this->getMyCompanyFields());
		}

		$fields = array_merge($fields, $this->getEntitySpecificFields());
		if ($settings->isRecurringSupported() && !isset($fields['isRecurring']))
		{
			$fields['isRecurring'] = new FieldDescriptor('isRecurring', 'IS_RECURRING', FieldType::Bool);
		}

		return $this->filterByOrmSchema($fields);
	}

	/**
	 * Drops scalar descriptors whose ORM column the entity's data class does not declare.
	 *
	 * The shared field-set builders assume a uniform column layout across a family of entity types,
	 * but the actual ORM tables diverge: XML_ID exists on dynamic/SPA items but not on the deal /
	 * lead / contact / company / quote tables; a contact has no TITLE; a quote has no MOVED_TIME.
	 * A scalar the ORM entity lacks cannot go into setSelect, a filter or an order without an ORM
	 * exception, so it must not be advertised for this type. This mirrors the read-copy path, which
	 * already skips such columns via hasField ({@see Mapper\ItemFieldMapper::copyScalarFields()}).
	 *
	 * Relation-backed fields (multifields, product rows, contact/company bindings, observers, UTM)
	 * are never single ORM scalar columns - dedicated Loaders read them and the Mapper writes them -
	 * so they are kept regardless of hasField.
	 *
	 * When the data class can't be resolved (e.g. an unbound smart-process type whose config row was
	 * removed), the fields are returned unchanged: the repository short-circuits such reads to empty
	 * results anyway.
	 *
	 * @param array<string, FieldDescriptor> $fields
	 * @return array<string, FieldDescriptor>
	 */
	private function filterByOrmSchema(array $fields): array
	{
		$entity = $this->getOrmEntity();
		if ($entity === null)
		{
			return $fields;
		}

		return array_filter(
			$fields,
			static fn(FieldDescriptor $descriptor): bool =>
				self::isRelationFieldType($descriptor->type)
				|| $entity->hasField($descriptor->ormName),
		);
	}

	/**
	 * Resolves the ORM entity backing this entity type via the legacy Factory's data class (the same
	 * class the V2 repositories query). Returns null when no factory / data class is bound.
	 */
	private function getOrmEntity(): ?Entity
	{
		$factory = Container::getInstance()->getFactory($this->entityType->getId());
		$dataClass = $factory?->getDataClass();
		if ($dataClass === null || !is_a($dataClass, \Bitrix\Main\ORM\Data\DataManager::class, true))
		{
			return null;
		}

		return $dataClass::getEntity();
	}

	/**
	 * A relation-backed field is not a single ORM scalar column: it lives in a separate table (or,
	 * for UTM, across several columns) and is loaded / written by dedicated services rather than the
	 * main select. Such fields are exempt from the ORM-schema filter.
	 */
	private static function isRelationFieldType(FieldType $type): bool
	{
		return isset(self::RELATION_FIELD_TYPES[$type->value]);
	}

	/**
	 * Standard entities (Deal, Lead, Contact, Company, Quote) have non-standard ORM names
	 * (DATE_CREATE, MODIFY_BY_ID, OBSERVER_IDS, etc.).
	 * Dynamic/SmartProcess entities use standard names (CREATED_TIME, UPDATED_BY, OBSERVERS, etc.).
	 */
	private function isStandardEntity(): bool
	{
		return in_array($this->entityType->getId(), [
			OwnerType::DEAL,
			OwnerType::LEAD,
			OwnerType::CONTACT,
			OwnerType::COMPANY,
			OwnerType::QUOTE,
		], true);
	}

	private function usesStatusId(): bool
	{
		return in_array($this->entityType->getId(), [
			OwnerType::LEAD,
			OwnerType::QUOTE,
		], true);
	}

	/** @return array<string, FieldDescriptor> */
	private function getCommonFields(): array
	{
		$legacy = $this->isStandardEntity();
		$legacyObservers = $legacy && $this->getEntityType()->getId() !== OwnerType::DEAL;

		return [
			'id' => new FieldDescriptor('id', 'ID', FieldType::Int, readonly: true),
			'createdTime' => new FieldDescriptor('createdTime', $legacy ? 'DATE_CREATE' : 'CREATED_TIME', FieldType::Datetime, readonly: true),
			'updatedTime' => new FieldDescriptor('updatedTime', $legacy ? 'DATE_MODIFY' : 'UPDATED_TIME', FieldType::Datetime, readonly: true),
			'createdById' => new FieldDescriptor('createdById', $legacy ? 'CREATED_BY_ID' : 'CREATED_BY', FieldType::Int, readonly: true),
			'updatedById' => new FieldDescriptor('updatedById', $legacy ? 'MODIFY_BY_ID' : 'UPDATED_BY', FieldType::Int, readonly: true),
			'title' => new FieldDescriptor('title', 'TITLE', FieldType::String),
			'xmlId' => new FieldDescriptor('xmlId', 'XML_ID', FieldType::String),
			'assignedById' => new FieldDescriptor('assignedById', 'ASSIGNED_BY_ID', FieldType::Int),
			'opened' => new FieldDescriptor('opened', 'OPENED', FieldType::Bool),
			'sourceId' => new FieldDescriptor('sourceId', 'SOURCE_ID', FieldType::String),
			'sourceDescription' => new FieldDescriptor('sourceDescription', 'SOURCE_DESCRIPTION', FieldType::String),
			'comments' => new FieldDescriptor('comments', 'COMMENTS', FieldType::String),
			'webformId' => new FieldDescriptor('webformId', 'WEBFORM_ID', FieldType::Int),
			'originatorId' => new FieldDescriptor('originatorId', 'ORIGINATOR_ID', FieldType::String),
			'originId' => new FieldDescriptor('originId', 'ORIGIN_ID', FieldType::String),
			'originVersion' => new FieldDescriptor('originVersion', 'ORIGIN_VERSION', FieldType::String),
			'observers' => new FieldDescriptor('observers', $legacyObservers ? 'OBSERVER_IDS' : 'OBSERVERS', FieldType::ObserverCollection),
			'utm' => new FieldDescriptor('utm', 'UTM', FieldType::Utm),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getLastActivityFields(): array
	{
		return [
			'lastActivityTime' => new FieldDescriptor('lastActivityTime', 'LAST_ACTIVITY_TIME', FieldType::Datetime, readonly: true),
			'lastActivityById' => new FieldDescriptor('lastActivityById', 'LAST_ACTIVITY_BY', FieldType::Int, readonly: true),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getStageFields(): array
	{
		$legacy = $this->isStandardEntity();
		$usesStatusId = $this->usesStatusId();
		$isLead = ($this->entityType->getId() === OwnerType::LEAD);

		return [
			'stageId' => new FieldDescriptor('stageId', $usesStatusId ? 'STATUS_ID' : 'STAGE_ID', FieldType::String),
			'stageSemanticId' => new FieldDescriptor('stageSemanticId', $isLead ? 'STATUS_SEMANTIC_ID' : 'STAGE_SEMANTIC_ID', FieldType::String, readonly: true),
			'movedTime' => new FieldDescriptor('movedTime', 'MOVED_TIME', FieldType::Datetime, readonly: true),
			'movedById' => new FieldDescriptor('movedById', $legacy ? 'MOVED_BY_ID' : 'MOVED_BY', FieldType::Int, readonly: true),
			'closed' => new FieldDescriptor('closed', 'CLOSED', FieldType::Bool, readonly: true),
			'beginDate' => new FieldDescriptor('beginDate', 'BEGINDATE', FieldType::Date),
			'closeDate' => new FieldDescriptor('closeDate', $isLead ? 'DATE_CLOSED' : 'CLOSEDATE', FieldType::Date),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getCategoryFields(): array
	{
		return [
			'categoryId' => new FieldDescriptor('categoryId', 'CATEGORY_ID', FieldType::Int),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getProductFields(): array
	{
		return [
			'opportunity' => new FieldDescriptor('opportunity', 'OPPORTUNITY', FieldType::Float),
			'isManualOpportunity' => new FieldDescriptor('isManualOpportunity', 'IS_MANUAL_OPPORTUNITY', FieldType::Bool),
			'taxValue' => new FieldDescriptor('taxValue', 'TAX_VALUE', FieldType::Float),
			'currencyId' => new FieldDescriptor('currencyId', 'CURRENCY_ID', FieldType::String),
			'exchRate' => new FieldDescriptor('exchRate', 'EXCH_RATE', FieldType::Float),
			'opportunityAccount' => new FieldDescriptor('opportunityAccount', 'OPPORTUNITY_ACCOUNT', FieldType::Float),
			'taxValueAccount' => new FieldDescriptor('taxValueAccount', 'TAX_VALUE_ACCOUNT', FieldType::Float),
			'accountCurrencyId' => new FieldDescriptor('accountCurrencyId', 'ACCOUNT_CURRENCY_ID', FieldType::String),
			'productRows' => new FieldDescriptor('productRows', 'PRODUCT_ROWS', FieldType::ProductRowCollection),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getContactBindingFields(): array
	{
		return [
			'contactBindings' => new FieldDescriptor('contactBindings', 'CONTACT_BINDINGS', FieldType::ContactBindingCollection),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getCompanyBindingFields(): array
	{
		return [
			'companyBindings' => new FieldDescriptor('companyBindings', 'COMPANY_BINDINGS', FieldType::CompanyBindingCollection),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getCompanyFields(): array
	{
		return [
			'companyId' => new FieldDescriptor('companyId', 'COMPANY_ID', FieldType::Int),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getMultifieldFields(): array
	{
		return [
			'phones' => new FieldDescriptor('phones', 'FM', FieldType::MultifieldCollection),
			'emails' => new FieldDescriptor('emails', 'FM', FieldType::MultifieldCollection),
			'webs' => new FieldDescriptor('webs', 'FM', FieldType::MultifieldCollection),
			'ims' => new FieldDescriptor('ims', 'FM', FieldType::MultifieldCollection),
			// Readonly flags populated by legacy when saving multifields
			'hasPhone' => new FieldDescriptor('hasPhone', 'HAS_PHONE', FieldType::Bool, readonly: true),
			'hasEmail' => new FieldDescriptor('hasEmail', 'HAS_EMAIL', FieldType::Bool, readonly: true),
			'hasImol' => new FieldDescriptor('hasImol', 'HAS_IMOL', FieldType::Bool, readonly: true),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getMyCompanyFields(): array
	{
		return [
			'myCompanyId' => new FieldDescriptor('myCompanyId', 'MYCOMPANY_ID', FieldType::Int),
		];
	}

	/** @return array<string, FieldDescriptor> */
	private function getEntitySpecificFields(): array
	{
		return match ($this->entityType->getId())
		{
			OwnerType::DEAL => [
				'typeId' => new FieldDescriptor('typeId', 'TYPE_ID', FieldType::String),
				'probability' => new FieldDescriptor('probability', 'PROBABILITY', FieldType::Float),
				'quoteId' => new FieldDescriptor('quoteId', 'QUOTE_ID', FieldType::Int),
				'additionalInfo' => new FieldDescriptor('additionalInfo', 'ADDITIONAL_INFO', FieldType::String),
				'isNew' => new FieldDescriptor('isNew', 'IS_NEW', FieldType::Bool, readonly: true),
				'isRepeatedApproach' => new FieldDescriptor('isRepeatedApproach', 'IS_REPEATED_APPROACH', FieldType::Bool, readonly: true),
				'leadId' => new FieldDescriptor('leadId', 'LEAD_ID', FieldType::Int),
				'isRecurring' => new FieldDescriptor('isRecurring', 'IS_RECURRING', FieldType::Bool),
				'isReturnCustomer' => new FieldDescriptor('isReturnCustomer', 'IS_RETURN_CUSTOMER', FieldType::Bool, readonly: true),
				'locationId' => new FieldDescriptor('locationId', 'LOCATION_ID', FieldType::Int),
				'previousStageId' => new FieldDescriptor('previousStageId', 'PREVIOUS_STAGE_ID', FieldType::String, readonly: true),
			],
			OwnerType::LEAD => [
				'birthdate' => new FieldDescriptor('birthdate', 'BIRTHDATE', FieldType::Date),
				'honorific' => new FieldDescriptor('honorific', 'HONORIFIC', FieldType::String),
				'name' => new FieldDescriptor('name', 'NAME', FieldType::String),
				'lastName' => new FieldDescriptor('lastName', 'LAST_NAME', FieldType::String),
				'secondName' => new FieldDescriptor('secondName', 'SECOND_NAME', FieldType::String),
				'post' => new FieldDescriptor('post', 'POST', FieldType::String),
				'companyTitle' => new FieldDescriptor('companyTitle', 'COMPANY_TITLE', FieldType::String),
				'statusDescription' => new FieldDescriptor('statusDescription', 'STATUS_DESCRIPTION', FieldType::String),
				'isReturnCustomer' => new FieldDescriptor('isReturnCustomer', 'IS_RETURN_CUSTOMER', FieldType::Bool, readonly: true),
			],
			OwnerType::CONTACT => [
				'leadId' => new FieldDescriptor('leadId', 'LEAD_ID', FieldType::Int, readonly: true),
				'honorific' => new FieldDescriptor('honorific', 'HONORIFIC', FieldType::String),
				'name' => new FieldDescriptor('name', 'NAME', FieldType::String),
				'lastName' => new FieldDescriptor('lastName', 'LAST_NAME', FieldType::String),
				'secondName' => new FieldDescriptor('secondName', 'SECOND_NAME', FieldType::String),
				'post' => new FieldDescriptor('post', 'POST', FieldType::String),
				'birthdate' => new FieldDescriptor('birthdate', 'BIRTHDATE', FieldType::Date),
				'birthdaySort' => new FieldDescriptor('birthdaySort', 'BIRTHDAY_SORT', FieldType::Int, readonly: true),
				'photo' => new FieldDescriptor('photo', 'PHOTO', FieldType::File),
				'export' => new FieldDescriptor('export', 'EXPORT', FieldType::Bool),
				'typeId' => new FieldDescriptor('typeId', 'TYPE_ID', FieldType::String),
			],
			OwnerType::COMPANY => [
				'leadId' => new FieldDescriptor('leadId', 'LEAD_ID', FieldType::Int, readonly: true),
				'currencyId' => new FieldDescriptor('currencyId', 'CURRENCY_ID', FieldType::String),
				'logo' => new FieldDescriptor('logo', 'LOGO', FieldType::File),
				'industry' => new FieldDescriptor('industry', 'INDUSTRY', FieldType::String),
				'employees' => new FieldDescriptor('employees', 'EMPLOYEES', FieldType::String),
				'revenue' => new FieldDescriptor('revenue', 'REVENUE', FieldType::Float),
				'isMyCompany' => new FieldDescriptor('isMyCompany', 'IS_MY_COMPANY', FieldType::Bool),
				'typeId' => new FieldDescriptor('typeId', 'COMPANY_TYPE', FieldType::String),
			],
			OwnerType::QUOTE => [
				'content' => new FieldDescriptor('content', 'CONTENT', FieldType::String),
				'terms' => new FieldDescriptor('terms', 'TERMS', FieldType::String),
				'quoteNumber' => new FieldDescriptor('quoteNumber', 'QUOTE_NUMBER', FieldType::String),
				'dealId' => new FieldDescriptor('dealId', 'DEAL_ID', FieldType::Int),
				'leadId' => new FieldDescriptor('leadId', 'LEAD_ID', FieldType::Int),
				'actualDate' => new FieldDescriptor('actualDate', 'ACTUAL_DATE', FieldType::Date),
				'personTypeId' => new FieldDescriptor('personTypeId', 'PERSON_TYPE_ID', FieldType::Int, readonly: true),
				'locationId' => new FieldDescriptor('locationId', 'LOCATION_ID', FieldType::Int),
			],
			OwnerType::SMART_INVOICE => [
				'accountNumber' => new FieldDescriptor('accountNumber', 'ACCOUNT_NUMBER', FieldType::String),
				'locationId' => new FieldDescriptor('locationId', 'LOCATION_ID', FieldType::Int),
			],
			OwnerType::SMART_DOCUMENT => [
				'number' => new FieldDescriptor('number', 'NUMBER', FieldType::String),
			],
			OwnerType::SMART_B2E_DOCUMENT => [
				'number' => new FieldDescriptor('number', 'NUMBER', FieldType::String),
			],
			default => [],
		};
	}
}
