<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Item\Param;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Provider\Params\SelectInterface;

/**
 * Caller-side spec for "which fields and relations should the Provider load for an Item".
 */
final class ItemSelect implements SelectInterface
{
	public const WILDCARD = '*';

	/**
	 * @var string[] camelCase standard field names, raw `UF_*` custom-field names, or
	 *               {@see WILDCARD} for "all standard scalars"
	 */
	private array $fields;

	private bool $loadProductRows = false;
	private bool $loadPhones = false;
	private bool $loadEmails = false;
	private bool $loadWebs = false;
	private bool $loadIms = false;
	private bool $loadContactBindings = false;
	private bool $loadCompanyBindings = false;
	private bool $loadEmployee = false;
	private bool $loadObservers = false;
	private bool $loadAllCustomFields = false;
	private bool $loadParents = false;
	private bool $loadUtm = false;
	private bool $loadTrackingSource = false;
	/** @var array<string, array{fieldName: string, entityTypeId: int|null, allowedCategoryIds: int[]|null, multiple: bool, dtoClass: string|null, select: string[], loaders: array{multifields: string[], utm: bool, lastCommunication: bool}}> */
	private array $loadRelatedCrmObjects = [];
	/** @var array<string, string[]> */
	private array $loadDictionariesSelect = [];
	private bool $loadLastCommunication = false;
	/** @var string[] */
	private array $loadLastCommunicationSelect = [];
	/** @var array<string, string[]> */
	private array $loadCustomFieldDataSelect = [];

	/**
	 * @param string ...$fields camelCase standard field names and/or raw `UF_*` custom-field names
	 *                          (mixed conventions are allowed, e.g. `'title', 'UF_CRM_FOO'`); at
	 *                          least one is required. Pass {@see WILDCARD} (or use {@see all()}) to
	 *                          load every standard scalar (custom fields are NOT included - use
	 *                          {@see withCustomFields()} for those).
	 * @throws ArgumentException When no field names are supplied.
	 */
	public function __construct(string ...$fields)
	{
		if (empty($fields))
		{
			throw new ArgumentException(
				'ItemSelect needs at least one field name; use ItemSelect::all() to load every standard scalar.',
				'fields',
			);
		}

		// `id` is implicit so the returned Item round-trips through Commands without surprises.
		$this->fields = array_values(array_unique(array_merge(['id'], $fields)));
	}

	/**
	 * Loads every standard scalar field for the entity type.
	 */
	public static function all(): self
	{
		return new self(self::WILDCARD);
	}

	/**
	 * Normalizes Provider input: an existing {@see ItemSelect} stays as-is, a plain camelCase
	 * `string[]` is wrapped via the variadic constructor.
	 *
	 * @param self|string[] $value
	 * @throws ArgumentException When an empty array is given.
	 */
	public static function from(self|array $value): self
	{
		return $value instanceof self ? $value : new self(...$value);
	}

	public function withProductRows(bool $enabled = true): static
	{
		$this->loadProductRows = $enabled;

		return $this;
	}

	public function withPhones(bool $enabled = true): static
	{
		$this->loadPhones = $enabled;

		return $this;
	}

	public function withEmails(bool $enabled = true): static
	{
		$this->loadEmails = $enabled;

		return $this;
	}

	public function withWebs(bool $enabled = true): static
	{
		$this->loadWebs = $enabled;

		return $this;
	}

	public function withIms(bool $enabled = true): static
	{
		$this->loadIms = $enabled;

		return $this;
	}

	/**
	 * Shorthand: enable (or disable) all four multifield types at once.
	 */
	public function withMultifields(bool $enabled = true): static
	{
		$this->loadPhones = $enabled;
		$this->loadEmails = $enabled;
		$this->loadWebs = $enabled;
		$this->loadIms = $enabled;

		return $this;
	}

	public function withContactBindings(bool $enabled = true): static
	{
		$this->loadContactBindings = $enabled;

		return $this;
	}

	public function withCompanyBindings(bool $enabled = true): static
	{
		$this->loadCompanyBindings = $enabled;

		return $this;
	}

	public function withEmployee(bool $enabled = true): static
	{
		$this->loadEmployee = $enabled;

		return $this;
	}

	public function withObservers(bool $enabled = true): static
	{
		$this->loadObservers = $enabled;

		return $this;
	}

	/**
	 * Hydrates every custom field (`UF_*`) defined for the entity type. Sugar for "all UF" - to
	 * load specific ones, pass their raw names to the constructor instead. Not covered by
	 * {@see all()}: the wildcard stays scalar-only.
	 */
	public function withCustomFields(bool $enabled = true): static
	{
		$this->loadAllCustomFields = $enabled;

		return $this;
	}

	/**
	 * Loads every parent relation (one per parent entity type) defined for the entity type. Parents
	 * are fetched from `b_crm_entity_relation` by a dedicated Loader, not the main ORM select, so -
	 * like custom fields - {@see all()} does not pull them in.
	 */
	public function withParents(bool $enabled = true): static
	{
		$this->loadParents = $enabled;

		return $this;
	}

	public function withUtm(bool $enabled = true): static
	{
		$this->loadUtm = $enabled;

		return $this;
	}

	/**
	 * Loads {@see \Bitrix\Crm\V2\Public\Entity\Item\Item::getTrackingSourceId()}.
	 */
	public function withTrackingSource(bool $enabled = true): static
	{
		$this->loadTrackingSource = $enabled;

		return $this;
	}

	/**
	 * Omit allowedCategoryIds to load every category; an empty list excludes every category.
	 *
	 * @param array<string, string[]|array{entityTypeId?: int|null, allowedCategoryIds?: int[], multiple?: bool, dtoClass?: string|null, select?: string[], fields?: string[]}> $selectMapByField
	 */
	public function withRelatedCrmObjects(array $selectMapByField): static
	{
		$this->loadRelatedCrmObjects = [];
		foreach ($selectMapByField as $fieldName => $select)
		{
			$this->loadRelatedCrmObjects[$fieldName] = $this->normalizeRelatedCrmObject($fieldName, $select);
		}

		$this->addFields(...$this->getRelatedCrmObjectSourceFields(array_keys($selectMapByField)));
		if (isset($selectMapByField['contact']) || isset($selectMapByField['contacts']))
		{
			$this->withContactBindings();
		}
		if (isset($selectMapByField['companies']))
		{
			$this->withCompanyBindings();
		}
		foreach (array_keys($selectMapByField) as $fieldName)
		{
			if (str_starts_with($fieldName, 'related'))
			{
				$this->withParents();

				break;
			}
		}

		return $this;
	}

	/** @param array<string, string[]> $selectMapByField */
	public function withDictionaries(array $selectMapByField): static
	{
		$this->loadDictionariesSelect = $selectMapByField;
		$this->addFields(...$this->getDictionarySourceFields(array_keys($selectMapByField)));

		return $this;
	}

	/** @param string[] $select */
	public function withLastCommunication(array $select): static
	{
		$this->loadLastCommunication = true;
		$this->loadLastCommunicationSelect = $select;

		return $this;
	}

	/** @param array<string, string[]> $selectMapByField */
	public function withCustomFieldData(array $selectMapByField): static
	{
		$this->loadCustomFieldDataSelect = $selectMapByField;

		return $this;
	}

	/**
	 * The raw camelCase field list as supplied by the caller (always includes `'id'`; may
	 * contain {@see WILDCARD}). The Provider resolves these to ORM column names.
	 *
	 * @return string[]
	 */
	public function getFields(): array
	{
		return $this->fields;
	}

	public function withFields(string ...$fields): static
	{
		$this->addFields(...$fields);

		return $this;
	}

	public function isAll(): bool
	{
		return in_array(self::WILDCARD, $this->fields, true);
	}

	/**
	 * @return string[] camelCase names (the caller-supplied list). Implements
	 *                  {@see SelectInterface} so this ItemSelect plugs into
	 *                  {@see \Bitrix\Main\Provider\Params\GridParams}. The Provider
	 *                  uses {@see getFields()} and resolves names via FieldRegistry.
	 */
	public function prepareSelect(): array
	{
		return $this->fields;
	}

	public function shouldLoadProductRows(): bool
	{
		return $this->loadProductRows;
	}

	public function shouldLoadPhones(): bool
	{
		return $this->loadPhones;
	}

	public function shouldLoadEmails(): bool
	{
		return $this->loadEmails;
	}

	public function shouldLoadWebs(): bool
	{
		return $this->loadWebs;
	}

	public function shouldLoadIms(): bool
	{
		return $this->loadIms;
	}

	public function shouldLoadAnyMultifield(): bool
	{
		return $this->loadPhones || $this->loadEmails || $this->loadWebs || $this->loadIms;
	}

	public function shouldLoadContactBindings(): bool
	{
		return $this->loadContactBindings;
	}

	public function shouldLoadCompanyBindings(): bool
	{
		return $this->loadCompanyBindings;
	}

	public function shouldLoadEmployee(): bool
	{
		return $this->loadEmployee;
	}

	public function shouldLoadObservers(): bool
	{
		return $this->loadObservers;
	}

	public function shouldLoadAllCustomFields(): bool
	{
		return $this->loadAllCustomFields;
	}

	public function shouldLoadParents(): bool
	{
		return $this->loadParents;
	}

	public function shouldLoadUtm(): bool
	{
		return $this->loadUtm;
	}

	public function shouldLoadTrackingSource(): bool
	{
		return $this->loadTrackingSource;
	}

	public function shouldLoadRelatedCrmObjects(): bool
	{
		return $this->loadRelatedCrmObjects !== [];
	}

	/** @return array<string, string[]> */
	public function getRelatedCrmObjectsSelect(): array
	{
		$result = [];
		foreach ($this->loadRelatedCrmObjects as $fieldName => $descriptor)
		{
			$result[$fieldName] = $descriptor['select'];
		}

		return $result;
	}

	/**
	 * @return array<string, array{fieldName: string, entityTypeId: int|null, allowedCategoryIds: int[]|null, multiple: bool, dtoClass: string|null, select: string[], loaders: array{multifields: string[], utm: bool, lastCommunication: bool}}>
	 */
	public function getRelatedCrmObjects(): array
	{
		return $this->loadRelatedCrmObjects;
	}

	public function shouldLoadDictionaries(): bool
	{
		return $this->loadDictionariesSelect !== [];
	}

	/** @return array<string, string[]> */
	public function getDictionariesSelect(): array
	{
		return $this->loadDictionariesSelect;
	}

	public function shouldLoadLastCommunication(): bool
	{
		return $this->loadLastCommunication;
	}

	/** @return string[] */
	public function getLastCommunicationSelect(): array
	{
		return $this->loadLastCommunicationSelect;
	}

	public function shouldLoadCustomFieldData(): bool
	{
		return $this->loadCustomFieldDataSelect !== [];
	}

	/** @return array<string, string[]> */
	public function getCustomFieldDataSelect(): array
	{
		return $this->loadCustomFieldDataSelect;
	}

	private function addFields(string ...$fields): void
	{
		$this->fields = array_values(array_unique(array_merge($this->fields, $fields)));
	}

	/**
	 * @param string[]|array{entityTypeId?: int|null, allowedCategoryIds?: int[], multiple?: bool, dtoClass?: string|null, select?: string[], fields?: string[]} $select
	 * @return array{fieldName: string, entityTypeId: int|null, allowedCategoryIds: int[]|null, multiple: bool, dtoClass: string|null, select: string[], loaders: array{multifields: string[], utm: bool, lastCommunication: bool}}
	 */
	private function normalizeRelatedCrmObject(string $fieldName, array $select): array
	{
		$isDescriptor = array_intersect(
			['entityTypeId', 'allowedCategoryIds', 'multiple', 'dtoClass', 'select', 'fields'],
			array_keys($select),
		) !== [];
		$selectedFields = $isDescriptor ? ($select['select'] ?? $select['fields'] ?? []) : $select;
		$selectedFields = array_values(array_unique(array_filter(
			$selectedFields,
			static fn(mixed $field): bool => is_string($field),
		)));

		$multifields = [];
		foreach ($selectedFields as $selectedField)
		{
			[$topLevelField] = explode('.', $selectedField, 2);
			if (in_array($topLevelField, ['phone', 'email', 'web', 'im'], true))
			{
				$multifields[$topLevelField] = true;
			}
		}

		return [
			'fieldName' => $fieldName,
			'entityTypeId' => isset($select['entityTypeId']) ? (int)$select['entityTypeId'] : null,
			'allowedCategoryIds' => array_key_exists('allowedCategoryIds', $select)
				? array_values(array_unique(array_map('intval', $select['allowedCategoryIds'])))
				: null,
			'multiple' => (bool)($select['multiple'] ?? in_array($fieldName, ['contacts', 'companies'], true)),
			'dtoClass' => isset($select['dtoClass']) && is_string($select['dtoClass']) ? $select['dtoClass'] : null,
			'select' => $selectedFields,
			'loaders' => [
				'multifields' => array_keys($multifields),
				'utm' => in_array('utm', array_map(static fn(string $field): string => explode('.', $field, 2)[0], $selectedFields), true),
				'lastCommunication' => in_array('lastCommunication', array_map(static fn(string $field): string => explode('.', $field, 2)[0], $selectedFields), true),
			],
		];
	}

	/**
	 * @param string[] $fields
	 * @return string[]
	 */
	private function getRelatedCrmObjectSourceFields(array $fields): array
	{
		$result = [];
		foreach ($fields as $field)
		{
			match (true)
			{
				$field === 'company' => $result[] = 'companyId',
				$field === 'myCompany' => $result[] = 'myCompanyId',
				$field === 'deal' => $result[] = 'dealId',
				$field === 'lead' => $result[] = 'leadId',
				$field === 'quote' => $result[] = 'quoteId',
				$field === 'contact', $field === 'contacts' => $result[] = 'contactBindings',
				$field === 'companies' => $result[] = 'companyBindings',
				default => null,
			};
		}

		return $result;
	}

	/**
	 * @param string[] $fields
	 * @return string[]
	 */
	private function getDictionarySourceFields(array $fields): array
	{
		$result = [];
		foreach ($fields as $field)
		{
			match ($field)
			{
				'stage' => $result[] = 'stageId',
				'previousStage' => $result[] = 'previousStageId',
				'stageSemantic' => $result[] = 'stageSemanticId',
				'source' => $result[] = 'sourceId',
				'category' => $result[] = 'categoryId',
				'currency' => $result[] = 'currencyId',
				'webform' => $result[] = 'webformId',
				'type' => $result[] = 'typeId',
				'honorific' => $result[] = 'honorific',
				default => null,
			};
		}

		return $result;
	}
}
