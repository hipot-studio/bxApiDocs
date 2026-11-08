<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item;

use Bitrix\Crm\UserField\Visibility\VisibilityManager;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Application;

/**
 * @internal
 */
class CustomFieldRegistry
{
	private const FILTERABLE_AND_SORTABLE_TYPES = [
		'string',
		'url',
		'address',
		'rich_text',
		'money',
		'integer',
		'double',
		'boolean',
		'date',
		'datetime',
		'enumeration',
		'crm_status',
		'iblock_element',
		'iblock_section',
		'employee',
		'crm',
	];

	private static ?self $instance = null;

	/**
	 * Per-instance memo of the descriptor map keyed by resolved UF-entity id (e.g. `CRM_DEAL`).
	 * The map is user-independent (descriptors do not depend on the reader), so it is safe to reuse
	 * for the whole request across the getList/getByIds hot path instead of rebuilding it per item.
	 *
	 * @var array<string, array<string, CustomFieldDescriptor>>
	 */
	private array $descriptorsMapByEntityId = [];

	public function __construct(
		private readonly \CUserTypeManager $userTypeManager,
	)
	{
	}

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self(Application::getUserTypeManager());
		}

		return self::$instance;
	}

	/**
	 * @return array<string, string>
	 */
	public function getVisibleFieldNames(EntityType $entityType): array
	{
		return
			$this
				->getUserTypeManager(\CCrmOwnerType::ResolveUserFieldEntityID($entityType->getId()))
				->getFieldNames()
		;
	}

	/**
	 * @return array<string, array>
	 */
	public function getUserFields(EntityType $entityType, string $languageId, int $userId): array
	{
		return $this->userTypeManager->getUserFields(
			\CCrmOwnerType::ResolveUserFieldEntityID($entityType->getId()),
			0,
			$languageId,
			$userId,
		);
	}

	/**
	 * @return string[]
	 */
	public function getHiddenFieldNames(EntityType $entityType, ?int $userId): array
	{
		return $this->getNotAccessibleFields(
			$entityType->getId(),
			null,
			$userId,
		);
	}

	/**
	 * Whether `$name` is a known custom field (`UF_*`) of the given entity type. User-independent
	 * (uses the system descriptor set), so it fits the read-path resolve without a user context.
	 */
	public function isCustomField(EntityType $entityType, string $name): bool
	{
		return isset($this->getEntityDescriptorsMap($entityType)[$name]);
	}

	/**
	 * Whether `$name` is a known multiple-value custom field (`UF_*`) of the entity type. False for
	 * single-value UF and for unknown names. User-independent.
	 */
	public function isMultipleCustomField(EntityType $entityType, string $name): bool
	{
		return $this->getEntityDescriptorsMap($entityType)[$name]?->isMultiple ?? false;
	}

	/**
	 * All custom-field names (`UF_*`) defined for the entity type. User-independent.
	 *
	 * @return string[]
	 */
	public function getCustomFieldNames(EntityType $entityType): array
	{
		return array_keys($this->getEntityDescriptorsMap($entityType));
	}

	/**
	 * Descriptor map (name -> {@see CustomFieldDescriptor}) for the entity type, resolving the
	 * CRM UF-entity id (e.g. `CRM_DEAL`) from the numeric type id. User-independent.
	 *
	 * @return array<string, CustomFieldDescriptor>
	 */
	public function getEntityDescriptorsMap(EntityType $entityType): array
	{
		return $this->getCustomFieldDescriptorsMapByName(
			\CCrmOwnerType::ResolveUserFieldEntityID($entityType->getId()),
		);
	}

	/**
	 * Descriptor map (name -> {@see CustomFieldDescriptor}) for a resolved UF-entity id. Memoized on
	 * the instance ({@see $descriptorsMapByEntityId}): descriptors are user-independent, so the map
	 * is built once per entity id and reused across the read hot path instead of per item/field.
	 *
	 * @return array<string, CustomFieldDescriptor>
	 */
	public function getCustomFieldDescriptorsMapByName(string $customFieldEntityId): array
	{
		return $this->descriptorsMapByEntityId[$customFieldEntityId] ??=
			$this->buildCustomFieldDescriptorsMapByName($customFieldEntityId);
	}

	/**
	 * @return array<string, CustomFieldDescriptor>
	 */
	private function buildCustomFieldDescriptorsMapByName(string $customFieldEntityId): array
	{
		$userTypeManager = $this->getUserTypeManager($customFieldEntityId);
		// User-independent descriptor set: skip the per-user UF visibility filter here (visibility is
		// applied separately via getHiddenFieldNames()). The raw field definitions are themselves
		// cached inside \CUserTypeManager.
		$customFieldDefinitions = $userTypeManager->getFields(['skipUserFieldVisibilityCheck' => true]);

		$fieldDescriptorsMapByName = [];
		foreach ($customFieldDefinitions as $customFieldDefinition)
		{
			if (!is_array($customFieldDefinition))
			{
				continue;
			}

			$fieldName = $customFieldDefinition['FIELD_NAME'] ?? null;
			$fieldType = $customFieldDefinition['USER_TYPE_ID'] ?? null;
			if (!is_string($fieldName) || !is_string($fieldType))
			{
				continue;
			}

			$fieldId = $customFieldDefinition['ID'] ?? null;
			if (is_int($fieldId))
			{
				if ($fieldId <= 0)
				{
					continue;
				}
			}
			elseif (!is_string($fieldId) || preg_match('/^[1-9]\d*$/D', $fieldId) !== 1)
			{
				continue;
			}

			$isSupportedType = in_array($fieldType, self::FILTERABLE_AND_SORTABLE_TYPES, true);
			$fieldDescriptorsMapByName[$fieldName] = new CustomFieldDescriptor(
				(int)$fieldId,
				name: $fieldName,
				type: $fieldType,
				isMultiple: ($customFieldDefinition['MULTIPLE'] ?? null) === 'Y',
				isRequired: ($customFieldDefinition['MANDATORY'] ?? null) === 'Y',
				isSearchable: ($customFieldDefinition['IS_SEARCHABLE'] ?? null) === 'Y',
				isFilterable: $isSupportedType && ($customFieldDefinition['SHOW_FILTER'] ?? null) !== 'N',
				isSortable: $isSupportedType && ($customFieldDefinition['MULTIPLE'] ?? null) !== 'Y',
				entityTypesId: $this->getEntityTypesId($customFieldDefinition),
				statusTypeId: $this->getStatusTypeId($customFieldDefinition),
				iBlockId: $this->getIBlockId($customFieldDefinition),
			);
		}

		return $fieldDescriptorsMapByName;
	}

	protected function getUserTypeManager(string $customFieldEntityId): \CCrmUserType
	{
		return new \CCrmUserType($this->userTypeManager, $customFieldEntityId);
	}

	/**
	 * @param string[]|null $userAccessCodes
	 * @param int|null $userId
	 * @return string[]
	 */
	protected function getNotAccessibleFields(
		int $entityTypeId,
		?array $userAccessCodes = null,
		?int $userId = null,
	): array
	{
		return VisibilityManager::getNotAccessibleFields($entityTypeId, $userAccessCodes, $userId);
	}

	protected function getIBlockId(array $customFieldDefinition): ?int
	{
		$typeId = $customFieldDefinition['USER_TYPE_ID'] ?? '';
		if (in_array($typeId, ['iblock_section', 'iblock_element'], true))
		{
			$iBlockId = $this->getSettings($customFieldDefinition)['IBLOCK_ID'] ?? null;
			if (is_int($iBlockId))
			{
				return $iBlockId > 0 ? $iBlockId : null;
			}

			if (!is_string($iBlockId) || preg_match('/^\d+$/D', $iBlockId) !== 1)
			{
				return null;
			}

			$iBlockId = (int)$iBlockId;

			return $iBlockId > 0 ? $iBlockId : null;
		}

		return null;
	}

	/**
	 * @return int[]|null
	 */
	protected function getEntityTypesId(array $customFieldDefinition): ?array
	{
		$typeId = $customFieldDefinition['USER_TYPE_ID'] ?? '';
		if ($typeId !== 'crm')
		{
			return null;
		}

		$elementTypesId = [];
		foreach ($this->getSettings($customFieldDefinition) as $elementTypeName => $isEnabled)
		{
			if (!is_string($elementTypeName))
			{
				continue;
			}

			$entityType = EntityType::fromName($elementTypeName);
			if ($entityType !== null && ($isEnabled === true || $isEnabled === 'Y'))
			{
				$elementTypesId[] = $entityType->getId();
			}
		}

		return $elementTypesId;
	}

	protected function getStatusTypeId(array $customFieldDefinition): ?string
	{
		$typeId = $customFieldDefinition['USER_TYPE_ID'] ?? '';
		if ($typeId !== 'crm_status')
		{
			return null;
		}

		// Stored settings may hold the whole entity-type entry instead of its id, the way legacy
		// readers of this field already expect (see \Bitrix\Crm\UserField\Types\StatusType).
		$entityType = $this->getSettings($customFieldDefinition)['ENTITY_TYPE'] ?? null;
		if (is_array($entityType))
		{
			$entityType = $entityType['ID'] ?? null;
		}

		return is_string($entityType) ? $entityType : null;
	}

	/**
	 * @return array<string|int, mixed>
	 */
	private function getSettings(array $customFieldDefinition): array
	{
		$settings = $customFieldDefinition['SETTINGS'] ?? null;

		return is_array($settings) ? $settings : [];
	}
}
