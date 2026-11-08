<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\Mixins\ManualStartDocumentTrait;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Activity\Configurator;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Crm\Integration\BizProc\Activity\Mixins\EventOutputsTrait;
use Bitrix\Crm\Integration\BizProc\Trigger\CategoryProperty;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('ManualStartTrigger'))
{
	return;
}

if (!\Bitrix\Main\Loader::includeModule('crm'))
{
	return;
}

class CBPCrmSmartManualStartTrigger extends \CBPManualStartTrigger
{
	use EventOutputsTrait;
	use ManualStartDocumentTrait;

	private const EVENT_INITIATOR_ID = 'Initiator';
	private const EVENT_DATE_TIME_ID = 'EventDateTime';

	private const PARAM_DOCUMENT = 'Document';
	private const PARAM_CATEGORY_ID = 'categoryId';
	private const PARAM_SMART_TYPE_ID = 'smartTypeId';
	private const PARAM_ONLY_AUTOMATED_SOLUTION = 'onlyAutomatedSolution';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->initManualStartDocumentProperties();

		$this->arProperties[self::PARAM_DOCUMENT] = null;
		$this->arProperties[self::PARAM_CATEGORY_ID] = null;
		// Value of a node saved before the selector: initializeFromArray() drops keys absent from this array.
		$this->arProperties[self::PARAM_SMART_TYPE_ID] = null;
		$this->arProperties[self::PARAM_ONLY_AUTOMATED_SOLUTION] = 'N';
		$this->arProperties[self::EVENT_INITIATOR_ID] = null;
		$this->arProperties[self::EVENT_DATE_TIME_ID] = null;
		$this->setPropertiesTypes([
			self::PARAM_DOCUMENT => [
				'Type' => FieldType::DOCUMENT_TYPE,
			],
			// Declared apart from arProperties: clearProperties() walks this map to clear the saved value.
			self::PARAM_SMART_TYPE_ID => [
				'Type' => FieldType::STRING,
			],
			self::PARAM_CATEGORY_ID => [
				'Type' => FieldType::SELECT,
			],
			self::PARAM_ONLY_AUTOMATED_SOLUTION => [
				'Type' => FieldType::BOOL,
			],
			self::EVENT_INITIATOR_ID => [
				'Type' => FieldType::USER,
			],
			self::EVENT_DATE_TIME_ID => [
				'Type' => FieldType::DATETIME,
			],
		]);
	}

	/**
	 * Single point that resolves the document value of the node: the selector property wins, the value of
	 * a node saved before the selector is the fallback. The only place that reads the older property.
	 */
	private static function resolveDocumentFromProperties(array $properties): string
	{
		$document = $properties[self::PARAM_DOCUMENT] ?? null;
		if (is_string($document) && $document !== '')
		{
			return $document;
		}

		$savedSmartType = $properties[self::PARAM_SMART_TYPE_ID] ?? null;

		return is_string($savedSmartType) && $savedSmartType !== '' ? $savedSmartType : '';
	}

	private function resolveDocument(): string
	{
		return self::resolveDocumentFromProperties($this->arProperties);
	}

	/**
	 * Single point that reads an entity type out of the raw value of the control, and the only form of the
	 * value the node trusts. The document class in the middle of the triple is what the permission
	 * dispatcher asks about the document type, and it arrives from the caller: nothing but the entity type
	 * survives here, and the triple itself is rebuilt from it by CCrmBizProcHelper. A value that is neither
	 * a triple nor an entity type name resolves to nothing instead of being taken apart.
	 */
	private static function resolveEntityTypeIdFromValue(string $document): int
	{
		if (str_contains($document, '@') && count(explode('@', $document)) !== 3)
		{
			return 0;
		}

		return CategoryProperty::resolveEntityTypeId($document);
	}

	public function execute(): int
	{
		$initiatorUserId = $this->resolveEventInitiatorUserId();
		$this->setProperties([
			static::getReturnDocumentFieldName() => $this->getDocumentId(),
			...static::buildEventOutputs($initiatorUserId),
		]);
		$this->setPropertiesTypes([
			static::getReturnDocumentFieldName() => $this->getReturnDocumentMapTypeForInstance(),
		]);

		return CBPActivityExecutionStatus::Closed;
	}

	protected function getReturnDocumentMapTypeForInstance(): array
	{
		return static::getReturnDocumentMapType($this->resolveDocument());
	}

	protected static function getReturnDocumentMapType(?string $document = null): array
	{
		if (!$document)
		{
			return [
				'Name' => Loc::getMessage('BP_CRM_FCT_DOCUMENT'),
				'Type' => FieldType::DOCUMENT,
				'Default' => null,
			];
		}

		$documentType = static::resolveDocumentType($document);

		return [
			'Name' =>
				$documentType
					? static::getDocumentName($documentType)
					: (Loc::getMessage('BP_CRM_FCT_DOCUMENT') ?? '')
			,
			'Type' => FieldType::DOCUMENT,
			'Default' => $documentType,
		];
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
		$result = parent::getPropertiesDialogValues(
			$documentType,
			$activityName,
			$workflowTemplate,
			$workflowParameters,
			$workflowVariables,
			$currentValues,
			$errors,
		);

		if (!$result)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$properties = $currentActivity['Properties'];

		$dialogErrors = self::validateDialogProperties(
			(array)$properties,
			new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser),
		);
		if ($dialogErrors)
		{
			$errors = $dialogErrors;

			return false;
		}

		$properties['Return'] = [
			static::getReturnDocumentFieldName() => static::getReturnDocumentMapType(
				self::resolveDocumentFromProperties((array)$currentValues),
			),
		];

		$currentActivity['Properties'] = $properties;

		return true;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		if (!\Bitrix\Main\Loader::includeModule(static::getModuleId()))
		{
			return [];
		}

		$onlyAutomatedSolution = (
				$context['Properties'][self::PARAM_ONLY_AUTOMATED_SOLUTION]
				?? $context[self::PARAM_ONLY_AUTOMATED_SOLUTION]
				?? 'N'
			) === 'Y';
		$scopeOption = $onlyAutomatedSolution ? 'onlyAutomatedSolution' : 'onlyDynamic';

		$selectedDocument =
			self::resolveDocumentFromProperties((array)($context['Properties'] ?? []))
			?: self::resolveDocumentFromProperties($context)
		;

		// Canonical type of the value and never the value itself: the funnel map is built from the entity type
		// alone, the same way every other point of the node reads what it was given.
		$selectedType = self::resolveDocumentType($selectedDocument);
		$document = $selectedType ? implode('@', $selectedType) : '';

		$map = [
			self::PARAM_DOCUMENT => [
				'Name' => Loc::getMessage('BP_CRM_DEAL_MANUAL_START_TRIGGER_FIELD_SMART_TYPE'),
				'FieldName' => self::PARAM_DOCUMENT,
				'Type' => FieldType::DOCUMENT_TYPE,
				'Multiple' => false,
				'Required' => true,
				'AllowSelection' => false,
				'Settings' => [
					'entity' => [
						'options' => [
							'moduleIds' => ['crm'],
							'crm' => [
								'onlyBizProcEnabled' => true,
								$scopeOption => true,
							],
						],
					],
				],
				'Getter' => static function($dialog, $property, $activity, $compatible = false) {
					$documentType = self::resolveDocumentType(
						self::resolveDocumentFromProperties((array)($activity['Properties'] ?? [])),
					);

					return $documentType ? implode('@', $documentType) : null;
				},
			],
		];

		$map += self::getCategoryPropertyMap($document);

		$map[self::PARAM_ONLY_AUTOMATED_SOLUTION] = [
			'FieldName' => self::PARAM_ONLY_AUTOMATED_SOLUTION,
			'Type' => FieldType::BOOL,
			'Default' => $onlyAutomatedSolution ? 'Y' : 'N',
			'Hidden' => true,
		];

		return $map;
	}

	/**
	 * The shared property carries the caption of the entity triggers, and Loc keeps messages flat per language:
	 * the node overwrites the caption in the map it got instead of declaring the shared code as its own.
	 */
	private static function getCategoryPropertyMap(string $document): array
	{
		$map = CategoryProperty::buildPropertyMap($document);
		if ($map)
		{
			$map[CategoryProperty::FIELD_NAME]['Name'] =
				Loc::getMessage('BP_CRM_DEAL_MANUAL_START_TRIGGER_FIELD_CATEGORY_ID')
			;
			$map[CategoryProperty::FIELD_NAME]['Multiple'] =
				\Bitrix\Main\Loader::includeModule('bizproc')
				&& method_exists(Configurator::class, 'getSections')
			;
		}

		return $map;
	}

	/**
	 * Resends the funnels of the process the dialog has just picked. The core action behind it checks the
	 * authoring right against the document type of the request body, which carries the service type of the
	 * workflow, while the process itself arrives as a parameter of its own: without the checks here any
	 * process of the portal would be enumerated by substituting the parameter.
	 *
	 * The answer is an object rather than a list: an empty PHP array reaches the client as [], which its
	 * Type.isPlainObject guard rejects, and "the process has no funnels" is an answer, not a refusal.
	 */
	public static function getAjaxResponse(array $request): \stdClass
	{
		return (object)self::resolveCategoryOptions($request);
	}

	/**
	 * The same three checks the picked value goes through on its way out of the settings dialog, in the same
	 * order and on the same canonical type: the value of the parameter is client input here as much as it is
	 * there. The two of them the validation of a template no longer applies stay mandatory here - this point
	 * answers a caller of its own, and nothing else limits what it enumerates.
	 */
	private static function resolveCategoryOptions(array $request): array
	{
		$document = $request['document'] ?? null;
		if (!is_string($document) || $document === '')
		{
			return [];
		}

		$entityTypeId = self::resolveEntityTypeIdFromValue($document);
		$documentType = $entityTypeId > 0 ? CCrmBizProcHelper::resolveDocumentType($entityTypeId) : null;
		if (!$documentType)
		{
			return [];
		}

		if (!self::isEntityTypeInPresetScope($entityTypeId, self::isOnlyAutomatedSolution($request)))
		{
			return [];
		}

		$user = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);

		return self::canUserCreateWorkflow($documentType, $user)
			? CategoryProperty::getOptions(implode('@', $documentType))
			: []
		;
	}

	/**
	 * The one point every entry into a template passes: the transport of the designer, an import, REST and the
	 * AI converter alike ({@see CBPWorkflowTemplateLoader::validateTemplate()}). Only a value that cannot work
	 * at all is refused here - an empty one and one that resolves into no document type - because a template
	 * carrying a node nobody touched has to stay savable even when the process of that node has been deleted,
	 * moved between the presets or is not the saving user's to author.
	 *
	 * The scope of the preset and the authoring right belong to the moment a process is picked, and are applied
	 * on the interactive path alone: {@see self::validateDialogProperties()} on the way out of the settings
	 * dialog and {@see self::resolveCategoryOptions()} on the point that resends the funnels.
	 */
	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$arErrors = [];

		$document = self::resolveDocumentFromProperties((array)$arTestProperties);

		if ($document === '')
		{
			$arErrors[] = self::createDocumentError('BP_CRM_DEAL_MANUAL_START_TRIGGER_FIELD_SMART_TYPE_ID_EMPTY');
		}
		elseif (!self::resolveDocumentType($document))
		{
			$arErrors[] = self::createDocumentError('BP_CRM_DEAL_MANUAL_START_TRIGGER_FIELD_SMART_TYPE_NOT_FOUND');
		}

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}

	/**
	 * The two rules the validation of a template deliberately leaves out, applied on the properties the save of
	 * the settings dialog has just composed: the value judged here is the one about to be written into the node,
	 * and an empty or unresolvable one has already been refused by the pipeline of the base trigger.
	 */
	private static function validateDialogProperties(array $properties, CBPWorkflowTemplateUser $user): array
	{
		$entityTypeId = self::resolveEntityTypeIdFromValue(self::resolveDocumentFromProperties($properties));
		$documentType = $entityTypeId > 0 ? CCrmBizProcHelper::resolveDocumentType($entityTypeId) : null;
		if (!$documentType)
		{
			return [];
		}

		if (!self::isEntityTypeInPresetScope($entityTypeId, self::isOnlyAutomatedSolution($properties)))
		{
			return [self::createDocumentError('BP_CRM_DEAL_MANUAL_START_TRIGGER_FIELD_SMART_TYPE_UNAVAILABLE')];
		}

		if (!self::canUserCreateWorkflow($documentType, $user))
		{
			return [self::createDocumentError('BP_CRM_DEAL_MANUAL_START_TRIGGER_FIELD_SMART_TYPE_ACCESS_DENIED')];
		}

		return [];
	}

	private static function createDocumentError(string $messageId): array
	{
		return [
			'code' => self::PARAM_DOCUMENT,
			'message' => Loc::getMessage($messageId) ?? '',
		];
	}

	private static function isOnlyAutomatedSolution(array $properties): bool
	{
		return CBPHelper::getBool($properties[self::PARAM_ONLY_AUTOMATED_SOLUTION] ?? 'N');
	}

	/**
	 * Same predicate that EventHandler::onGetDocumentType() applies to build the list of the selector.
	 */
	private static function isEntityTypeInPresetScope(int $entityTypeId, bool $onlyAutomatedSolution): bool
	{
		$type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);
		if (!$type || ($type->getCustomSectionId() !== null) !== $onlyAutomatedSolution)
		{
			return false;
		}

		return Container::getInstance()->getFactory($entityTypeId)?->isBizProcEnabled() ?? false;
	}

	private static function canUserCreateWorkflow(array $documentType, CBPWorkflowTemplateUser $user): bool
	{
		// The selector lets a workflow template admin through without asking the document type.
		return
			$user->isAdmin()
			|| CBPDocument::canUserOperateDocumentType(
				CBPCanUserOperateOperation::CreateWorkflow,
				$user->getId(),
				$documentType,
			)
		;
	}

	protected function getSection(): ?Section
	{
		return $this->getSections()[0] ?? null;
	}

	protected function getSections(): array
	{
		$entityTypeId = self::resolveEntityTypeIdFromValue($this->resolveDocument());
		if ($entityTypeId <= 0)
		{
			return [];
		}

		$sectionId = static::getModuleId() . '|' . CCrmOwnerType::ResolveName($entityTypeId);
		$paths = self::resolveCategoryPaths($this->arProperties[self::PARAM_CATEGORY_ID] ?? null);
		if (!$paths)
		{
			return [new Section($sectionId)];
		}

		return array_map(static fn(string $path): Section => new Section($sectionId, $path), $paths);
	}

	private static function resolveCategoryPaths(mixed $categoryId): array
	{
		$categoryIds = is_array($categoryId) ? $categoryId : [$categoryId];

		$paths = [];
		foreach ($categoryIds as $value)
		{
			if (is_array($value))
			{
				continue;
			}

			$normalizedId = CategoryProperty::normalizeId($value);
			if ($normalizedId === null)
			{
				continue;
			}

			$path = (string)$normalizedId;
			$paths[$path] = $path;
		}

		return array_values($paths);
	}

	protected static function getModuleId(): string
	{
		return 'crm';
	}

	/**
	 * The type the node publishes and registers itself with is the canonical type of its entity, and never
	 * the triple of the saved value: that value is the raw value of the control, so the document class in
	 * it would otherwise pick the handler every permission check of the node goes through.
	 */
	protected static function resolveDocumentType(?string $document = null): ?array
	{
		$entityTypeId = $document === null ? 0 : self::resolveEntityTypeIdFromValue($document);

		return $entityTypeId > 0 ? CCrmBizProcHelper::resolveDocumentType($entityTypeId) : null;
	}

	protected function getDocumentComplexType(): array
	{
		$document = $this->resolveDocument();
		if ($document === '')
		{
			return parent::getDocumentComplexType();
		}

		return static::resolveDocumentType($document) ?? [];
	}
}
