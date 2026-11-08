<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Integration\UI\EntitySelector\DocumentTypeProvider;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Bizproc\Result;
use Bitrix\Crm\Integration\BizProc\Activity\Mixins\EventOutputsTrait;
use Bitrix\Crm\Integration\BizProc\Trigger\CategoryProperty;
use Bitrix\Crm\Integration\BizProc\Trigger\TrackedFieldsProperty;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('FieldChangedTrigger'))
{
	return;
}

if (!\Bitrix\Main\Loader::includeModule('crm'))
{
	return;
}

class CBPCrmEntityFieldChangedTrigger extends CBPFieldChangedTrigger
{
	use EventOutputsTrait;

	public const REACTION_MODE_ID = 'ReactionMode';
	public const MODE_FIELDS = 'fields';
	public const MODE_ANY = 'any';

	private const EVENT_INITIATOR_ID = 'Initiator';
	private const EVENT_DATE_TIME_ID = 'EventDateTime';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties[self::REACTION_MODE_ID] = self::MODE_FIELDS;

		// return
		$this->arProperties['ReturnDocument'] = null;
		$this->arProperties['ChangedFields'] = null;
		$this->arProperties['IsAutomatedSolution'] = 'N';
		$this->arProperties['categoryId'] = null;
		$this->arProperties[self::EVENT_INITIATOR_ID] = null;
		$this->arProperties[self::EVENT_DATE_TIME_ID] = null;
	}

	public function checkApplyRules(array $rules, TriggerParameters $parameters): Result
	{
		// the entity type and category filter applies in both modes, the changed fields only in the fields mode
		if ($this->getReactionMode() !== self::MODE_ANY)
		{
			$fieldsResult = parent::checkApplyRules($rules, $parameters);
			if (!$fieldsResult->isSuccess())
			{
				return $fieldsResult;
			}
		}

		return $this->checkCategoryApplyRules($parameters);
	}

	private function getReactionMode(): string
	{
		return static::normalizeReactionMode($this->getActivityProperty(self::REACTION_MODE_ID));
	}

	private function checkCategoryApplyRules(TriggerParameters $parameters): Result
	{
		$expectedCategoryIds = CategoryProperty::normalizeIds($this->getActivityProperty('categoryId'));
		if ($expectedCategoryIds === [])
		{
			return Result::createOk();
		}

		$actualCategoryId = CategoryProperty::normalizeId(
			$parameters->get('CategoryId') ?? $parameters->get('categoryId'),
		);

		return in_array($actualCategoryId, $expectedCategoryIds, true)
			? Result::createOk()
			: Result::createError(new \Bitrix\Bizproc\Error('category mismatch'));
	}

	protected function getSection(): ?Section
	{
		$entityTypeId = CategoryProperty::resolveEntityTypeId($this->getActivityProperty('Document'));
		if ($entityTypeId <= 0)
		{
			return null;
		}

		$categoryId = CategoryProperty::normalizeId($this->getActivityProperty('categoryId'));

		return new Section(
			'crm|' . \CCrmOwnerType::ResolveName($entityTypeId),
			$categoryId !== null ? (string)$categoryId : null,
		);
	}

	protected function getSections(): array
	{
		if (!CategoryProperty::supportsMultipleCategories())
		{
			return parent::getSections();
		}

		$entityTypeId = CategoryProperty::resolveEntityTypeId($this->getActivityProperty('Document'));
		if ($entityTypeId <= 0)
		{
			return [];
		}

		$categoryIds = CategoryProperty::normalizeIds($this->getActivityProperty('categoryId'));
		if ($categoryIds === [])
		{
			return [$this->getSection()];
		}

		$sectionId = 'crm|' . \CCrmOwnerType::ResolveName($entityTypeId);

		return array_map(
			static fn(int $categoryId): Section => new Section($sectionId, (string)$categoryId),
			$categoryIds,
		);
	}

	public function execute(): int
	{
		parent::execute();

		$context = $this->getEventData();

		$availableTrackedFields = TrackedFieldsProperty::getOptions($this->getRawProperty('Document') ?? '');
		$document = $context['Document'] ?? null;
		$changedFields = array_values(
			array_intersect($context['Fields'] ?? [], array_keys($availableTrackedFields))
		);

		$initiatorUserId = $this->resolveEventInitiatorUserId();

		$this->setProperties([
			'ReturnDocument' => $document,
			'ChangedFields' => $changedFields,
			...static::buildEventOutputs($initiatorUserId),
		]);

		$this->setPropertiesTypes([
			'ReturnDocument' => static::getReturnDocumentMapType($document),
			'ChangedFields' => static::getChangedFieldsDocumentMapType($availableTrackedFields),
			self::EVENT_INITIATOR_ID => ['Type' => \Bitrix\Bizproc\FieldType::USER],
			self::EVENT_DATE_TIME_ID => ['Type' => \Bitrix\Bizproc\FieldType::DATETIME],
		]);

		return CBPActivityExecutionStatus::Closed;
	}

	/**
	 * Fields is only mandatory when the node reacts to the selected fields: in the any-change mode the
	 * value is kept in the properties but takes no part in the check. The base class makes Fields
	 * mandatory unconditionally, so its validation is replaced rather than extended.
	 */
	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];

		if (\CBPHelper::isEmptyValue($arTestProperties['Document'] ?? null))
		{
			$errors[] = [
				'code' => 'Document',
				'message' => \Bitrix\Main\Localization\Loc::getMessage('BPFCT_DOCUMENT_EMPTY'),
			];
		}

		$reactionMode = static::normalizeReactionMode($arTestProperties[self::REACTION_MODE_ID] ?? null);
		if ($reactionMode !== self::MODE_ANY && \CBPHelper::isEmptyValue($arTestProperties['Fields'] ?? null))
		{
			$errors[] = [
				'code' => 'Fields',
				'message' => \Bitrix\Main\Localization\Loc::getMessage('BPFCT_FIELDS_EMPTY'),
			];
		}

		// parent:: would restore the replaced Fields check, and BaseTrigger in between declares no
		// validateProperties() of its own - revisit this call if it ever does
		return array_merge($errors, \CBPActivity::validateProperties($arTestProperties, $user));
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		$savedReactionMode = static::readSavedReactionMode($workflowTemplate, $activityName);
		if (is_array($currentValues))
		{
			// resolved before the parent call so that the mode-aware validation runs against the resolved mode
			$currentValues[self::REACTION_MODE_ID] = static::resolveReactionModeOnSave(
				$currentValues[self::REACTION_MODE_ID] ?? null,
				$savedReactionMode,
			);
		}

		$result = parent::getPropertiesDialogValues(
			$documentType,
			$activityName,
			$workflowTemplate,
			$workflowParameters,
			$workflowVariables,
			$currentValues,
			$errors
		);

		if (!$result)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$properties = $currentActivity['Properties'];

		// keeps the stored property one of the two modes even if extracting the value yielded nothing
		$reactionMode = static::resolveReactionModeOnSave(
			$properties[self::REACTION_MODE_ID] ?? null,
			$savedReactionMode,
		);
		$properties[self::REACTION_MODE_ID] = $reactionMode;

		$properties['Return'] = [
			'ReturnDocument' => static::getReturnDocumentMapType(
				static::resolveDocumentTypeFromDocument($properties['Document'] ?? '')
			),
			'ChangedFields' => static::getChangedFieldsDocumentMapType(
				static::resolveChangedFieldsOptions(
					$reactionMode,
					(string)($properties['Document'] ?? ''),
					$properties['Fields'] ?? [],
				)
			),
		];

		$currentActivity['Properties'] = $properties;

		return true;
	}

	private static function getReturnDocumentMapType(?array $document = null): array
	{
		return [
			'Name' =>
				$document
					? static::getDocumentName($document)
					: (\Bitrix\Main\Localization\Loc::getMessage('BP_CRM_FCT_DOCUMENT') ?? '')
			,
			'Type' => \Bitrix\Bizproc\FieldType::DOCUMENT,
			'Default' => $document,
		];
	}

	/**
	 * The any-change mode monitors no particular field, so narrowing the options down to Fields (empty
	 * there) would leave the expression editor without names for the values the node does publish:
	 * execute() fills ChangedFields from every tracked field of the document.
	 */
	private static function resolveChangedFieldsOptions(
		string $reactionMode,
		string $document,
		array $fields,
	): array
	{
		$trackedFields = TrackedFieldsProperty::getOptions($document);

		return $reactionMode === self::MODE_ANY
			? $trackedFields
			: array_intersect_key($trackedFields, array_flip($fields))
		;
	}

	private static function getChangedFieldsDocumentMapType(array $fields): array
	{
		return [
			'Name' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_FCT_CHANGED_FIELDS') ?? '',
			'Type' => \Bitrix\Bizproc\FieldType::SELECT,
			'Options' => $fields,
			'Multiple' => true,
		];
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$document = $context['Properties']['Document'] ?? $context['Document'] ?? '';
		$isAutomatedSolution = CBPHelper::getBool(
			$context['Properties']['IsAutomatedSolution'] ?? $context['IsAutomatedSolution'] ?? 'N'
		);

		$map = parent::getPropertiesMap($documentType, $context);
		$map = static::addReactionModeToPropertiesMap($map, static::readReactionModeFromContext($context));

		$complexDocumentType = static::resolveDocumentTypeFromDocument($document);

		$type = $complexDocumentType ? (string)$complexDocumentType[2] : (string)$document;
		$presetEntities = CategoryProperty::getPresetEntityNames();

		if (static::isEntitySelectorAvailable())
		{
			unset($map['Document']['Options']);
			$map['Document']['Type'] = \Bitrix\Bizproc\FieldType::DOCUMENT_TYPE;
			$map['Document']['Settings'] = [
				'entity' => [
					'options' => [
						'moduleIds' => ['crm'],
						'crm' => ['onlyBizProcEnabled' => true],
					],
				],
			];
			$map['Document']['Getter'] = static function($dialog, $property, $activity, $compatible = false) {
				$document = $activity['Properties']['Document'] ?? null;
				$complexType = static::resolveDocumentTypeFromDocument((string)$document);

				return $complexType ? implode('@', $complexType) : null;
			};

			if (in_array($type, $presetEntities, true))
			{
				$map['Document']['Hidden'] = true;
				$map['Document']['Settings']['entity']['options']['crm']['onlyEntities'] = [$type];
			}
			else
			{
				if ($isAutomatedSolution)
				{
					$map['Document']['Settings']['entity']['options']['crm']['onlyAutomatedSolution'] = true;
				}
				else
				{
					$map['Document']['Settings']['entity']['options']['crm']['onlyDynamic'] = true;
				}

				if ($isAutomatedSolution)
				{
					$map['IsAutomatedSolution'] = [
						'Name' => '',
						'FieldName' => 'IsAutomatedSolution',
						'Type' => \Bitrix\Bizproc\FieldType::BOOL,
						'Multiple' => false,
						'Required' => false,
						'Default' => 'Y',
						'Hidden' => true,
						'AllowSelection' => false,
					];
				}
			}

			return $map + CategoryProperty::buildPropertyMap((string)$document);
		}

		if (in_array($type, $presetEntities, true))
		{
			$map['Document']['Hidden'] = true;
			$map['Document']['Settings'] = array_merge($map['Document']['Settings'] ?? [], ['ShowEmptyValue' => false]);
			$map['Document']['Options'] = [$type => $map['Document']['Options'][$type]];
		}
		else
		{
			// remove preset entities
			$map['Document']['Options'] = array_filter(
				$map['Document']['Options'],
				static fn($key) => !in_array($key, $presetEntities, true),
				ARRAY_FILTER_USE_KEY
			);
		}

		return $map + CategoryProperty::buildPropertyMap((string)$document);
	}

	/**
	 * The map key order drives the form row order, so the mode selector goes right after Document and
	 * above the tracked-fields block. The Required flag makes no server-side check for triggers
	 * (BaseTrigger extends CBPActivity, not BaseActivity) - it drives the UI and the client validation,
	 * while the server-side obligation lives in validateProperties().
	 */
	private static function addReactionModeToPropertiesMap(array $map, string $reactionMode): array
	{
		foreach (['Document', 'Fields'] as $inheritedControl)
		{
			if (!isset($map[$inheritedControl]))
			{
				throw new \Bitrix\Main\SystemException(
					sprintf('The inherited properties map has no %s control', $inheritedControl)
				);
			}
		}

		$map = [
			'Document' => $map['Document'],
			self::REACTION_MODE_ID => static::buildReactionModeProperty(),
		] + $map;

		$requiredError = \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_FCT_FIELDS_REQUIRED_ERROR') ?? '';

		$map['Fields']['Required'] = $reactionMode !== self::MODE_ANY;
		$map['Fields']['Settings']['requiredErrorMessage'] = $requiredError;

		return $map;
	}

	private static function buildReactionModeProperty(): array
	{
		$fieldsLabel = \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_FCT_REACTION_MODE_FIELDS') ?? '';
		$anyLabel = \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_FCT_REACTION_MODE_ANY') ?? '';

		return [
			'Name' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_FCT_REACTION_MODE') ?? '',
			'FieldName' => self::REACTION_MODE_ID,
			'Type' => \Bitrix\Bizproc\FieldType::SELECT,
			'Multiple' => false,
			'Required' => true,
			'Default' => self::MODE_FIELDS,
			'Options' => [
				self::MODE_FIELDS => $fieldsLabel,
				self::MODE_ANY => $anyLabel,
			],
			// a single select renders an own empty option unless this is set, and picking it resets the mode
			'Settings' => ['ShowEmptyValue' => false],
			'AllowSelection' => false,
		];
	}

	/**
	 * The form context is nested when the form is built (CBPActivity::createConfigurator passes
	 * ['Properties' => $currentValues]) and flat on save (BaseTrigger::applyPropertiesDialogValues
	 * passes the submitted values as is).
	 */
	private static function readReactionModeFromContext(array $context): string
	{
		$mode = $context['Properties'][self::REACTION_MODE_ID] ?? $context[self::REACTION_MODE_ID] ?? null;

		return static::normalizeReactionMode($mode);
	}

	private static function normalizeReactionMode(mixed $mode): string
	{
		return static::isReactionMode($mode) ? $mode : self::MODE_FIELDS;
	}

	private static function isReactionMode(mixed $mode): bool
	{
		return in_array($mode, [self::MODE_FIELDS, self::MODE_ANY], true);
	}

	/**
	 * A missing, empty or unknown mode in the submitted values means "the form sent no field", not
	 * "the default was chosen": extracting values yields an empty value in both cases, so the mode of
	 * the saved node wins over the default. Resolution order: submitted value, saved property, default.
	 */
	private static function resolveReactionModeOnSave(mixed $submittedMode, mixed $savedMode): string
	{
		foreach ([$submittedMode, $savedMode] as $candidate)
		{
			if (static::isReactionMode($candidate))
			{
				return $candidate;
			}
		}

		return self::MODE_FIELDS;
	}

	private static function readSavedReactionMode(array $workflowTemplate, string $activityName): mixed
	{
		$savedActivity = CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);

		return $savedActivity['Properties'][self::REACTION_MODE_ID] ?? null;
	}

	private function getActivityProperty(string $name): mixed
	{
		return array_key_exists($name, $this->arProperties)
			? $this->arProperties[$name]
			: $this->getRawProperty($name);
	}

	protected static function getAvailableDocuments(): array
	{
		if (static::isEntitySelectorAvailable())
		{
			return [];
		}

		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return [];
		}

		$documents = [];

		$presetEntities = CategoryProperty::getPresetEntityNames();

		// Factory is not returned for orders
		$typesMap = \Bitrix\Crm\Service\Container::getInstance()->getTypesMap();
		foreach ($typesMap->getFactories() as $factory)
		{
			$entityTypeId = $factory->getEntityTypeId();
			$documentType = CCrmBizProcHelper::resolveDocumentType($entityTypeId);
			if (!$documentType)
			{
				continue;
			}

			$name = CCrmOwnerType::resolveName($entityTypeId);
			if (
				in_array($name, $presetEntities, true)
				|| (CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId) && !$factory->isInCustomSection())
			)
			{
				$documents[$name] = ['id' => $name, 'name' => static::getDocumentName($documentType)];
			}
		}

		$orderTypeId = CCrmOwnerType::Order;
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($orderTypeId);
		if ($factory)
		{
			$orderDocumentType = CCrmBizProcHelper::resolveDocumentType($orderTypeId);
			if ($orderDocumentType)
			{
				$name = CCrmOwnerType::resolveName($orderTypeId);
				$documents[$name] = [
					'id' => $name, 'name' => static::getDocumentName($orderDocumentType),
				];
			}
		}

		return $documents;
	}

	protected static function getDocumentName(array $documentType)
	{
		return CBPRuntime::getRuntime()->getDocumentService()->getDocumentTypeName($documentType);
	}

	protected static function resolveDocumentTypeFromDocument(string $document): ?array
	{
		return CategoryProperty::resolveDocumentTypeFromDocument($document);
	}

	public static function getConditionDocumentSourceFieldNames(): array
	{
		return ['Document'];
	}

	public function getConditionDocumentComplexType(): ?array
	{
		return static::resolveConditionDocumentType($this->getRawProperty('Document'));
	}

	public function getConditionDocumentId(TriggerParameters $parameters): ?array
	{
		$conditionType = $this->getConditionDocumentComplexType();
		$documentId = $parameters->get('Document');
		if (!static::isConditionDocumentIdOfType($documentId, $conditionType))
		{
			return null;
		}

		return array_values($documentId);
	}

	private static function resolveConditionDocumentType(mixed $document): ?array
	{
		if (!\CBPHelper::hasStringRepresentation($document))
		{
			return null;
		}

		$documentType = static::resolveDocumentTypeFromDocument((string)$document);

		return static::isComplexDocumentType($documentType) ? $documentType : null;
	}

	private static function isConditionDocumentIdOfType(mixed $documentId, ?array $conditionType): bool
	{
		return static::isComplexDocumentType($documentId)
			&& $conditionType !== null
			&& \CBPHelper::isEqualDocumentEntity($documentId, $conditionType)
		;
	}

	private static function isComplexDocumentType(mixed $document): bool
	{
		if (!is_array($document) || count($document) !== 3)
		{
			return false;
		}

		foreach ($document as $part)
		{
			if (!is_string($part) || $part === '')
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Server-fill the monitored Fields options from the current document, in parity with the ajax path
	 * (getAjaxResponse) and categoryId. The base getPropertiesMap fills the Fields SELECT via this hook;
	 * without the override it stays empty for a preset or saved node (no document-change JS event fires),
	 * so the chosen field is dropped on save and the mandatory-Fields validation rejects the node.
	 */
	protected static function getTrackedFields(string $document): array
	{
		return TrackedFieldsProperty::getOptions($document);
	}

	/**
	 * @param $request
	 * @return array{fields: array, categories: array}
	 */
	public static function getAjaxResponse($request): array
	{
		$document = $request['document'] ?? null;
		if (!is_string($document) || $document === '')
		{
			return ['fields' => [], 'categories' => []];
		}

		return [
			'fields' => TrackedFieldsProperty::getOptions($document),
			'categories' => CategoryProperty::getOptions($document),
		];
	}

	protected static function isEntitySelectorAvailable(): bool
	{
		return defined(DocumentTypeProvider::class . '::PRESELECTED_ITEMS_SUPPORTED');
	}

	/**
	 * The presets belong to the class rather than to the descriptor: the starter module settings resolve
	 * the trigger of a converted legacy template through getPresetByComplexDocumentType(), where only the
	 * activity file has been included. The descriptor takes its PRESETS from here.
	 */
	public static function getPresets(): array
	{
		\Bitrix\Main\Loader::includeModule('ui');

		// the preset phrases ship with the descriptor, whose lang file includeActivityFile() does not load
		\Bitrix\Main\Localization\Loc::loadMessages(__DIR__ . '/.description.php');

		$isEntitySelectorAvailable = static::isEntitySelectorAvailable();

		$presets = [
			[
				'ID' => 'DEAL',
				'NAME' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_DEAL_FCT_DESCR_NAME_MSGVER_1'),
				'DESCRIPTION' => static::getPresetMessage('DEAL', 'DESCR_1'),
				'PROPERTIES' => ['Document' => $isEntitySelectorAvailable ? 'crm@CCrmDocumentDeal@DEAL' : 'DEAL'],
				'NODE_ICON' => Outline::HANDSHAKE->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
				],
			],
			[
				'ID' => 'CONTACT',
				'NAME' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_CONTACT_FCT_DESCR_NAME_MSGVER_1'),
				'DESCRIPTION' => static::getPresetMessage('CONTACT', 'DESCR_1'),
				'PROPERTIES' => [
					'Document' => $isEntitySelectorAvailable ? 'crm@CCrmDocumentContact@CONTACT' : 'CONTACT',
				],
				'NODE_ICON' => Outline::CONTACT->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
				],
			],
			[
				'ID' => 'COMPANY',
				'NAME' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_COMPANY_FCT_DESCR_NAME_MSGVER_1'),
				'DESCRIPTION' => static::getPresetMessage('COMPANY', 'DESCR_1'),
				'PROPERTIES' => [
					'Document' => $isEntitySelectorAvailable ? 'crm@CCrmDocumentCompany@COMPANY' : 'COMPANY',
				],
				'NODE_ICON' => Outline::COMPANY->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
				],
			],
			[
				'ID' => 'LEAD',
				'NAME' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_LEAD_FCT_DESCR_NAME_MSGVER_1'),
				'DESCRIPTION' => static::getPresetMessage('LEAD', 'DESCR_1'),
				'PROPERTIES' => ['Document' => $isEntitySelectorAvailable ? 'crm@CCrmDocumentLead@LEAD' : 'LEAD'],
				'NODE_ICON' => Outline::LEAD->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
				],
			],
		];

		if ($isEntitySelectorAvailable)
		{
			$presets[] = [
				'ID' => 'QUOTE',
				'NAME' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_QUOTE_FCT_DESCR_NAME_MSGVER_1'),
				'DESCRIPTION' => static::getPresetMessage('QUOTE', 'DESCR_1'),
				'PROPERTIES' => ['Document' => 'crm@Bitrix\Crm\Integration\BizProc\Document\Quote@QUOTE'],
				'NODE_ICON' => Outline::SUITCASE->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
				],
			];
			$presets[] = [
				'ID' => 'AUTOMATED_SOLUTION',
				'NAME' => \Bitrix\Main\Localization\Loc::getMessage(
					'BP_CRM_AUTOMATED_SOLUTION_FCT_DESCR_NAME_MSGVER_1',
				),
				'DESCRIPTION' => static::getPresetMessage('AUTOMATED_SOLUTION', 'DESCR_1'),
				'PROPERTIES' => ['IsAutomatedSolution' => 'Y'],
				'NODE_ICON' => Outline::SMART_PROCESS->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::DIGITAL_WORKPLACE->value,
				],
			];
			$presets[] = [
				'ID' => 'DYNAMIC',
				'NAME' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_DYNAMIC_FCT_DESCR_NAME_MSGVER_1'),
				'DESCRIPTION' => static::getPresetMessage('DYNAMIC', 'DESCR_1'),
				'NODE_ICON' => Outline::SMART_PROCESS->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
				],
			];
		}

		if (\CCrmSaleHelper::isWithOrdersMode())
		{
			$presets[] = [
				'ID' => 'ORDER',
				'NAME' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_ORDER_FCT_DESCR_NAME_MSGVER_1'),
				'DESCRIPTION' => static::getPresetMessage('ORDER', 'DESCR_1'),
				'PROPERTIES' => [
					'Document' => $isEntitySelectorAvailable
						? 'crm@Bitrix\Crm\Integration\BizProc\Document\Order@ORDER'
						: 'ORDER'
					,
				],
				'NODE_ICON' => Outline::CHANGE_ORDER->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
					ActivityGroup::PAYMENT->value,
				],
			];
		}

		if ($isEntitySelectorAvailable && \Bitrix\Crm\Settings\InvoiceSettings::getCurrent()->isSmartInvoiceEnabled())
		{
			$presets[] = [
				'ID' => 'SMART_INVOICE',
				'NAME' => \Bitrix\Main\Localization\Loc::getMessage('BP_CRM_SMART_INVOICE_FCT_DESCR_NAME_MSGVER_1'),
				'DESCRIPTION' => static::getPresetMessage('SMART_INVOICE', 'DESCR_1'),
				'PROPERTIES' => [
					'Document' => 'crm@Bitrix\Crm\Integration\BizProc\Document\SmartInvoice@SMART_INVOICE',
				],
				'NODE_ICON' => Outline::INVOICE->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
					ActivityGroup::PAYMENT->value,
				],
			];
		}

		foreach ($presets as $index => $preset)
		{
			$document = static::resolveDocumentTypeFromDocument(
				(string)($preset['PROPERTIES']['Document'] ?? ''),
			);
			if ($document === null)
			{
				continue;
			}

			$presets[$index]['PROPERTIES']['Return']['ReturnDocument'] = static::getReturnDocumentMapType($document);
		}

		return $presets;
	}

	public static function getPresetById(string $presetId): ?array
	{
		foreach (static::getPresets() as $preset)
		{
			if ($preset['ID'] === $presetId)
			{
				return $preset;
			}
		}

		return null;
	}

	public static function getPresetByComplexDocumentType(array $complexDocumentType): ?array
	{
		$presetId = static::resolvePresetIdByEntityTypeId(
			(int)\CCrmOwnerType::ResolveID((string)($complexDocumentType[2] ?? ''))
		);

		return $presetId === null ? null : static::getPresetById($presetId);
	}

	private static function resolvePresetIdByEntityTypeId(int $entityTypeId): ?string
	{
		if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);

			return $factory?->isInCustomSection() ? 'AUTOMATED_SOLUTION' : 'DYNAMIC';
		}

		return match ($entityTypeId)
		{
			\CCrmOwnerType::Deal => 'DEAL',
			\CCrmOwnerType::Contact => 'CONTACT',
			\CCrmOwnerType::Company => 'COMPANY',
			\CCrmOwnerType::Lead => 'LEAD',
			\CCrmOwnerType::Quote => 'QUOTE',
			\CCrmOwnerType::Order => 'ORDER',
			\CCrmOwnerType::SmartInvoice => 'SMART_INVOICE',
			default => null,
		};
	}

	/**
	 * Own phrase-code template: Loc stores messages flat per language, so a code shared with the create
	 * trigger would make the lang file loaded last overwrite the preset titles of the other one.
	 */
	protected static function getPresetMessagePrefix(): string
	{
		return 'BP_CRM_%s_FCT_DESCR';
	}

	private static function getPresetMessage(string $entityCode, string $suffix): ?string
	{
		return \Bitrix\Main\Localization\Loc::getMessage(
			sprintf(static::getPresetMessagePrefix(), $entityCode) . '_' . $suffix
		);
	}
}
