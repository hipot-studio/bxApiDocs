<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\FieldType;
use Bitrix\Crm;
use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\Binding\EntityBinding;
use Bitrix\Crm\Binding\LeadContactTable;
use Bitrix\Crm\Binding\QuoteContactTable;
use Bitrix\Crm\Conversion\ConversionManager;
use Bitrix\Crm\Integration\BizProc\Document\Item;
use Bitrix\Crm\Integration\BizProc\Document\Quote;
use Bitrix\Crm\RelationIdentifier;
use Bitrix\Crm\Service\Container;

class CBPCrmGetConversionDataActivity extends CBPActivity implements IBPConfigurableActivity
{
	private const TARGET_ENTITY_FIELDS = 'TargetEntityFields';
	private const SOURCE_DOCUMENT_PROPERTIES = 'SourceDocumentProperties';
	private const SOURCE_DOCUMENT = 'SourceDocument';
	private const RELATION_PACKAGE_SETTING = 'relationPackage';
	private const RELATION_PACKAGE_ROLE_SOURCE_DOCUMENT = 'sourceDocument';
	private const RELATION_PACKAGE_ROLE_TARGET_FIELD = 'targetField';

	private array $fieldsMap = [];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'TargetEntityType' => null,
			self::TARGET_ENTITY_FIELDS => null,
			self::SOURCE_DOCUMENT_PROPERTIES => null,
		];
	}

	protected function ReInitialize()
	{
		parent::ReInitialize();
		foreach ($this->fieldsMap as $field)
		{
			$this->__set($field, null);
		}
		$this->fieldsMap = [];
	}

	public function Execute()
	{
		if (!CModule::IncludeModule('crm'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$targetEntityType = $this->TargetEntityType;
		if (!$targetEntityType)
		{
			$this->WriteToTrackingService(GetMessage('CRM_GCDA_EMPTY_TARGET'), 0, CBPTrackingType::Error);

			return CBPActivityExecutionStatus::Closed;
		}

		$documentId = $this->GetDocumentId();
		if ($documentId[0] !== 'crm')
		{
			$this->WriteToTrackingService(GetMessage('CRM_GCDA_INCORRECT_DOCUMENT'), 0, CBPTrackingType::Error);

			return CBPActivityExecutionStatus::Closed;
		}

		$this->logDebugInput();

		[$entityTypeName, $entityId] = explode('_', $documentId[2]);
		$entityTypeId = \CCrmOwnerType::ResolveID($entityTypeName);
		$targetEntityTypeId = \CCrmOwnerType::ResolveID($targetEntityType);

		try
		{
			// The conversion wizard serves only legacy targets; dynamic-type targets go through the
			// conversion mapper and must not depend on a legacy conversion config being present.
			if (\CCrmOwnerType::isUseDynamicTypeBasedApproach($targetEntityTypeId))
			{
				$mappedFields = self::mapEntityFieldsByConversionMapper(
					$entityTypeId,
					(int)$entityId,
					$targetEntityTypeId,
				);
			}
			else
			{
				$wizard = $this->createWizard($entityTypeId, (int)$entityId);
				if (!$wizard)
				{
					$this->WriteToTrackingService(GetMessage('CRM_GCDA_CONVERTER_NOT_FOUND'), 0, CBPTrackingType::Error);

					return CBPActivityExecutionStatus::Closed;
				}

				$mappedFields = $wizard->mapEntityFields($targetEntityTypeId, []);
				if (!empty($mappedFields) && self::isContactOwningTarget($targetEntityTypeId))
				{
					unset($mappedFields[Crm\Item::FIELD_NAME_CONTACT_BINDINGS]);
					$mappedFields[Crm\Item::FIELD_NAME_CONTACT_IDS] = self::loadSourceContactIds(
						$entityTypeId,
						(int)$entityId,
					);
				}
			}

			$dstFields = self::formatUserValues($this->normalizeFields($mappedFields), $targetEntityTypeId);
		}
		catch (\Throwable $e)
		{
			$this->WriteToTrackingService($e->getMessage(), 0, CBPTrackingType::Error);

			return CBPActivityExecutionStatus::Closed;
		}

		if (empty($dstFields))
		{
			$this->WriteToTrackingService(GetMessage('CRM_GCDA_EMPTY_RESULT'), 0, CBPTrackingType::Error);

			return CBPActivityExecutionStatus::Closed;
		}

		$runtimeProperties = self::buildRuntimeProperties($documentId, $dstFields);

		$this->SetProperties($runtimeProperties);
		$this->fieldsMap = array_keys($runtimeProperties);

		$this->logDebugResult($runtimeProperties);

		return CBPActivityExecutionStatus::Closed;
	}

	private function createWizard(int $entityTypeId, int $entityId): ?Crm\Conversion\EntityConversionWizard
	{
		if ($entityTypeId === \CCrmOwnerType::Lead)
		{
			$config = new Crm\Conversion\LeadConversionConfig();
		}
		elseif (in_array($entityTypeId, [\CCrmOwnerType::Deal, \CCrmOwnerType::Quote], true))
		{
			$config = ConversionManager::getConfig($entityTypeId);
			if (!$config)
			{
				return null;
			}
		}
		else
		{
			return null;
		}

		$config->enablePermissionCheck(false);

		$wizard = ConversionManager::getWizard($entityTypeId, $entityId, $config);
		if (!$wizard)
		{
			return null;
		}

		$wizard->enableUserFieldCheck(false);
		$wizard->enableBizProcCheck(false);

		return $wizard;
	}

	private function logDebugInput(): void
	{
		if ($this->workflow->isDebug())
		{
			$this->writeDebugInfo($this->getDebugInfo());
		}
	}

	private function logDebugResult(array $fields): void
	{
		if ($this->workflow->isDebug())
		{
			$debugInfo = $this->getDebugInfo(
				$fields,
				array_combine(array_keys($fields), array_keys($fields))
			);
			$this->writeDebugInfo($debugInfo);
		}
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			'TargetEntityType' => [
				'Name' => GetMessage('CRM_GCDA_TARGET_ENTITY_TYPE'),
				'FieldName' => 'target_entity_type',
				'Type' => 'select',
				'Required' => true,
				'Options' => static::getTargetEntityOptions($documentType),
				'AllowSelection' => false,
				'Setter' => static::getTargetEntityTypePropertySetter($documentType),
			],
		];
	}

	/**
	 * The unified form submits the target entity only, while Execute() and the declared additional
	 * result also need the derived field maps, so this field owns both writes.
	 */
	private static function getTargetEntityTypePropertySetter(array $documentType): Closure
	{
		return static fn($value): array => self::buildActivityProperties($documentType, $value);
	}

	private static function buildActivityProperties(array $documentType, $targetEntityType): array
	{
		$properties = [
			'TargetEntityType' => $targetEntityType,
		];

		if ($targetEntityType && CModule::IncludeModule('crm'))
		{
			$srcEntityTypeId = self::resolveSourceEntityTypeId($documentType);
			if ($srcEntityTypeId !== null)
			{
				$properties[self::SOURCE_DOCUMENT_PROPERTIES] = self::buildSourceDocumentProperties($documentType);

				$dstEntityTypeId = \CCrmOwnerType::ResolveID($targetEntityType);
				$targetDocumentType = \CCrmBizProcHelper::ResolveDocumentType($dstEntityTypeId);
				if (!is_array($targetDocumentType))
				{
					return $properties;
				}

				$map = self::loadConversionMap($srcEntityTypeId, $dstEntityTypeId);
				if ($map)
				{
					$properties[self::TARGET_ENTITY_FIELDS] = self::buildTargetEntityFields(
						$dstEntityTypeId,
						$map,
						$targetDocumentType,
					);

					if (self::isContactOwningTarget($dstEntityTypeId))
					{
						$properties[self::TARGET_ENTITY_FIELDS] = self::addContactIdsField(
							$properties[self::TARGET_ENTITY_FIELDS],
							$dstEntityTypeId,
							$targetDocumentType,
						);
					}
				}
			}
		}

		return $properties;
	}

	private static function loadConversionMap(int $srcEntityTypeId, int $dstEntityTypeId): ?Crm\Conversion\EntityConversionMap
	{
		try
		{
			if (\CCrmOwnerType::isUseDynamicTypeBasedApproach($dstEntityTypeId))
			{
				return Container::getInstance()->getConversionMapper()->getMap(
					new RelationIdentifier($srcEntityTypeId, $dstEntityTypeId),
				);
			}

			// The stored map of a legacy pair belongs to the legacy converter, which creates and actualizes it
			// in a format of its own, so it is only read here.
			$map = Crm\Conversion\EntityConversionMap::load(
				$srcEntityTypeId,
				$dstEntityTypeId,
				['ENABLE_ACTUALIZATION' => false],
			);
			if ($map === null)
			{
				return self::createLegacyDefaultMap($srcEntityTypeId, $dstEntityTypeId);
			}

			return $map->isOutOfDate() ? self::createActualizedCopy($map) : $map;
		}
		catch (Exception $e)
		{
			return null;
		}
	}

	/**
	 * The loaded map is the instance EntityConversionMap::load() caches for the converter, and updateMap()
	 * changes the items of a map in place, so the user fields added since the map was stored are matched
	 * on a copy. Storing the actualized map is left to the converter.
	 */
	private static function createActualizedCopy(
		Crm\Conversion\EntityConversionMap $map,
	): Crm\Conversion\EntityConversionMap
	{
		$copy = new Crm\Conversion\EntityConversionMap();
		$copy->internalize($map->externalize());
		Crm\Conversion\EntityConversionMapper::updateMap($copy);

		return $copy;
	}

	private static function createLegacyDefaultMap(
		int $srcEntityTypeId,
		int $dstEntityTypeId,
	): ?Crm\Conversion\EntityConversionMap
	{
		try
		{
			return match ($srcEntityTypeId)
			{
				\CCrmOwnerType::Lead => Crm\Conversion\LeadConversionMapper::createMap($dstEntityTypeId),
				\CCrmOwnerType::Deal => Crm\Conversion\DealConversionMapper::createMap($dstEntityTypeId),
				\CCrmOwnerType::Quote => Crm\Conversion\QuoteConversionMapper::createMap($dstEntityTypeId),
				default => null,
			};
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private static function getTargetEntityOptions(array $documentType): array
	{
		$options = [];

		if (!CModule::IncludeModule('crm'))
		{
			return $options;
		}

		$srcEntityTypeId = self::resolveSourceEntityTypeId($documentType);
		if ($srcEntityTypeId === null)
		{
			return $options;
		}

		foreach (ConversionManager::getDestinationEntityTypeIDs($srcEntityTypeId) as $dstEntityTypeId)
		{
			$dstTypeName = \CCrmOwnerType::ResolveName($dstEntityTypeId);
			if ($dstTypeName === '')
			{
				continue;
			}
			$options[$dstTypeName] = \CCrmOwnerType::GetDescription($dstEntityTypeId);
		}

		return $options;
	}

	private static function resolveSourceEntityTypeId(array $documentType): ?int
	{
		if (!isset($documentType[1]))
		{
			return null;
		}

		return match ($documentType[1])
		{
			'CCrmDocumentLead' => \CCrmOwnerType::Lead,
			'CCrmDocumentDeal' => \CCrmOwnerType::Deal,
			Quote::class => \CCrmOwnerType::Quote,
			default => null,
		};
	}

	public static function ValidateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];

		if (empty($arTestProperties['TargetEntityType']))
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => 'TargetEntityType',
				'message' => GetMessage('CRM_GCDA_EMPTY_TARGET'),
			];
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	public static function GetPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = '',
	)
	{
		if (!CModule::IncludeModule('crm'))
		{
			return '';
		}

		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]);

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	public static function GetPropertiesDialogValues(
		$documentType,
		$activityName,
		&$arWorkflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$arCurrentValues,
		&$errors,
	)
	{
		$errors = [];

		$properties = self::buildActivityProperties($documentType, $arCurrentValues['target_entity_type'] ?? null);

		$errors = self::ValidateProperties(
			$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser),
		);

		if ($errors)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	private static function mapEntityFieldsByConversionMapper(
		int $srcEntityTypeId,
		int $entityId,
		int $targetEntityTypeId,
	): array
	{
		$srcFactory = Container::getInstance()->getFactory($srcEntityTypeId);
		if (!$srcFactory)
		{
			return [];
		}

		$map = self::loadConversionMap($srcEntityTypeId, $targetEntityTypeId);
		if (!$map)
		{
			return [];
		}

		$sourceFields = [];
		foreach ($map->getItems() as $mapItem)
		{
			if ($mapItem->getDestinationField() === '-')
			{
				continue;
			}

			$sourceFields[] = $mapItem->getSourceField();
		}

		if (!$sourceFields)
		{
			return [];
		}

		$sourceItem = $srcFactory->getItem($entityId, array_values(array_unique($sourceFields)));
		if (!$sourceItem)
		{
			return [];
		}

		$fields = [];
		foreach ($map->getItems() as $mapItem)
		{
			$dstFieldId = $mapItem->getDestinationField();
			if ($dstFieldId === '-')
			{
				continue;
			}

			$fields[$dstFieldId] = $sourceItem->get($mapItem->getSourceField());

			if ($mapItem->getSourceField() === Crm\Item::FIELD_NAME_PRODUCTS)
			{
				$fields[$dstFieldId] = $sourceItem->getProductRows()
					? $sourceItem->getProductRows()->toArray()
					: [];
			}
		}

		return $fields;
	}

	private static function buildTargetEntityFields(
		int $dstEntityTypeId,
		Crm\Conversion\EntityConversionMap $map,
		array $targetDocumentType,
	): array
	{
		try
		{
			$documentFields = Item::getEntityFields($dstEntityTypeId);
		}
		catch (\Throwable)
		{
			return [];
		}

		$factory = Container::getInstance()->getFactory($dstEntityTypeId);
		$targetEntityFields = [];

		$multifieldKeys = ['PHONE' => true, 'EMAIL' => true, 'WEB' => true, 'IM' => true, 'LINK' => true];

		foreach ($map->getItems() as $mapItem)
		{
			$fieldId = self::resolvePackageFieldId($mapItem->getDestinationField());
			if ($fieldId === '' || $fieldId === '-')
			{
				continue;
			}

			if ($fieldId === Crm\Item::FIELD_NAME_FM)
			{
				foreach (array_keys($multifieldKeys) as $multifieldKey)
				{
					if (isset($documentFields[$multifieldKey]))
					{
						$targetEntityFields[$multifieldKey] = self::markTargetFieldAsRelationPackage(
							$documentFields[$multifieldKey],
							$multifieldKey,
							$targetDocumentType,
						);
					}
				}
				continue;
			}

			$documentFieldId = self::findDocumentFieldId($factory, $fieldId, $documentFields);
			if ($documentFieldId !== null)
			{
				$targetEntityFields[$fieldId] = self::markTargetFieldAsRelationPackage(
					$documentFields[$documentFieldId],
					$documentFieldId,
					$targetDocumentType,
				);
			}
		}

		return $targetEntityFields;
	}

	/**
	 * The legacy converters map the contacts as CONTACT_BINDINGS, which the document of the target does not
	 * know: the package carries them under the code of the document field.
	 */
	private static function resolvePackageFieldId(string $mapDestinationField): string
	{
		return $mapDestinationField === Crm\Item::FIELD_NAME_CONTACT_BINDINGS
			? Crm\Item::FIELD_NAME_CONTACT_IDS
			: $mapDestinationField
		;
	}

	/**
	 * The contacts of these targets belong to the action itself, not to the conversion map: depending on
	 * the stored map they come as bindings, as a list or not at all (the map of the lead for the deal).
	 */
	private static function isContactOwningTarget(int $dstEntityTypeId): bool
	{
		return $dstEntityTypeId === \CCrmOwnerType::Deal || $dstEntityTypeId === \CCrmOwnerType::Quote;
	}

	private static function addContactIdsField(
		array $targetEntityFields,
		int $dstEntityTypeId,
		array $targetDocumentType,
	): array
	{
		$fieldId = Crm\Item::FIELD_NAME_CONTACT_IDS;
		if (isset($targetEntityFields[$fieldId]))
		{
			return $targetEntityFields;
		}

		try
		{
			$documentFields = Item::getEntityFields($dstEntityTypeId);
		}
		catch (\Throwable)
		{
			return $targetEntityFields;
		}

		if (isset($documentFields[$fieldId]))
		{
			$targetEntityFields[$fieldId] = self::markTargetFieldAsRelationPackage(
				$documentFields[$fieldId],
				$fieldId,
				$targetDocumentType,
			);
		}

		return $targetEntityFields;
	}

	/**
	 * @return list<int> The contacts of the source, the primary one first.
	 */
	private static function loadSourceContactIds(int $srcEntityTypeId, int $srcEntityId): array
	{
		$bindings = match ($srcEntityTypeId)
		{
			\CCrmOwnerType::Lead => LeadContactTable::getLeadBindings($srcEntityId),
			\CCrmOwnerType::Deal => DealContactTable::getDealBindings($srcEntityId),
			\CCrmOwnerType::Quote => QuoteContactTable::getQuoteBindings($srcEntityId),
			default => [],
		};

		$contactIds = EntityBinding::prepareEntityIDs(\CCrmOwnerType::Contact, $bindings);
		$primaryBinding = EntityBinding::findPrimaryBinding($bindings);
		if (is_array($primaryBinding))
		{
			$primaryContactId = EntityBinding::prepareEntityID(\CCrmOwnerType::Contact, $primaryBinding);
			$contactIds = [
				$primaryContactId,
				...array_filter($contactIds, static fn(int $contactId): bool => $contactId !== $primaryContactId),
			];
		}

		return array_values($contactIds);
	}

	private static function buildSourceDocumentProperties(array $sourceDocumentType): array
	{
		return [
			self::SOURCE_DOCUMENT => [
				'Name' => GetMessage('CRM_GCDA_SOURCE_DOCUMENT') ?: self::SOURCE_DOCUMENT,
				'Type' => FieldType::DOCUMENT,
				'Multiple' => false,
				'Default' => array_values($sourceDocumentType),
				'Settings' => [
					self::RELATION_PACKAGE_SETTING => [
						'role' => self::RELATION_PACKAGE_ROLE_SOURCE_DOCUMENT,
					],
				],
			],
		];
	}

	private static function buildRuntimeProperties(array $documentId, array $dstFields): array
	{
		return [
			...$dstFields,
			self::SOURCE_DOCUMENT => $documentId,
		];
	}

	/**
	 * The marker addresses the field of the target document form, so it carries the code the field was
	 * found under, while the property itself keeps the name of the conversion map destination: that name
	 * is the output the running activity publishes the value under, and an expression refers to it.
	 */
	private static function markTargetFieldAsRelationPackage(
		array $documentField,
		string $documentFieldId,
		array $targetDocumentType,
	): array
	{
		$settings = $documentField['Settings'] ?? [];
		if (!is_array($settings))
		{
			$settings = [];
		}

		$settings[self::RELATION_PACKAGE_SETTING] = [
			'role' => self::RELATION_PACKAGE_ROLE_TARGET_FIELD,
			'fieldId' => $documentFieldId,
			'targetDocumentType' => array_values($targetDocumentType),
		];

		$documentField['Settings'] = $settings;

		return $documentField;
	}

	private static function findDocumentFieldId(
		?Crm\Service\Factory $factory,
		string $fieldId,
		array $documentFields,
	): ?string
	{
		$lookupKeys = [$fieldId];
		if ($factory)
		{
			$entityFieldName = $factory->getEntityFieldNameByMap($fieldId);
			$lookupKeys[] = $entityFieldName;
			$lookupKeys[] = Item::convertFieldId($entityFieldName);
		}

		foreach (array_unique($lookupKeys) as $lookupKey)
		{
			if ($lookupKey !== '' && isset($documentFields[$lookupKey]))
			{
				return $lookupKey;
			}
		}

		return null;
	}

	/**
	 * Fields the target document types as "user" are published in the bizproc user format: to
	 * CBPHelper::ExtractUsers() an unprefixed number is a GROUP code, not a user id. The type is read
	 * from the target document by findDocumentFieldId, so a field it knows under another code counts.
	 */
	private static function formatUserValues(array $dstFields, int $dstEntityTypeId): array
	{
		try
		{
			$documentFields = Item::getEntityFields($dstEntityTypeId);
		}
		catch (\Throwable)
		{
			return $dstFields;
		}

		$factory = Container::getInstance()->getFactory($dstEntityTypeId);

		foreach ($dstFields as $fieldId => $value)
		{
			$documentFieldId = self::findDocumentFieldId($factory, (string)$fieldId, $documentFields);
			if ($documentFieldId === null || ($documentFields[$documentFieldId]['Type'] ?? '') !== FieldType::USER)
			{
				continue;
			}

			$dstFields[$fieldId] = is_array($value)
				? array_map(self::toUserValue(...), $value)
				: self::toUserValue($value);
		}

		return $dstFields;
	}

	/**
	 * Only a bare id is prefixed. Anything else the bizproc user format already accepts - a prefixed
	 * value, a group code, an extended group like "group_d5" - travels on untouched.
	 */
	private static function toUserValue(mixed $value): mixed
	{
		if (!is_int($value) && !is_string($value))
		{
			return $value;
		}

		$userId = (int)$value;

		return $userId > 0 && (string)$userId === (string)$value ? 'user_' . $userId : $value;
	}

	private function normalizeFields(array $mappedFields): array
	{
		$entityFields = [];
		foreach ($mappedFields as $k => $v)
		{
			if ($k === 'FM')
			{
				$this->internalizeMultifieldData($v, $entityFields);
			}
			else
			{
				$entityFields[$k] = $v;
			}
		}

		return $entityFields;
	}

	private function internalizeMultifieldData(array $data, array &$entityFields): void
	{
		foreach ($data as $typeName => $items)
		{
			if (!isset($entityFields[$typeName]))
			{
				$entityFields[$typeName] = [];
			}

			foreach ($items as $itemID => $item)
			{
				$entityFields[$typeName][] = array_merge(['ID' => $itemID], $item);
			}
		}
	}
}
