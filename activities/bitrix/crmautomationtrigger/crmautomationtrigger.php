<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Automation\Helper;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Error;
use Bitrix\Crm\Automation\Target\BaseTarget;
use Bitrix\Crm\Automation\Trigger\BaseTrigger;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class CBPCrmAutomationTrigger extends Bitrix\Bizproc\Activity\BaseTrigger
{
	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			'TriggerClass' => '',
			'Document' => '',
			'condition' => '',
			'AdditionalProperties' => [],
		];
	}

	public function execute(): int
	{
		parent::execute();

		$context = $this->getEventData();

		/** @var ?BaseTarget $target */
		$target = $context['TARGET'] ?? null;
		$document = $target?->getComplexDocumentId();

		/** @var class-string<BaseTrigger> $triggerClass */
		$triggerClass = $context['TRIGGER_CLASS'] ?? '';

		$properties = [];
		$returnProperties = [];

		if (self::isCrmAutomationTrigger($triggerClass))
		{
			$trigger = new $triggerClass();
			$properties =
				$trigger
					->setInputData($context['INPUT_DATA'])
					->getReturnValues() ?? $properties
			;

			$returnProperties = self::transformProperties($trigger::toArray()['RETURN'] ?? []);
		}

		if ($document)
		{
			$properties = [
				'ReturnDocument' => $document,
				...$properties,
			];
			$returnProperties = [
				'ReturnDocument' => static::getReturnDocumentMapType($document),
				...$returnProperties,
			];
		}

		$this->setProperties($properties);
		$this->setPropertiesTypes($returnProperties);

		return CBPActivityExecutionStatus::Closed;
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
			$errors
		);

		if (!$result)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$properties = $currentActivity['Properties'];

		/** @var class-string<BaseTrigger> $triggerClass */
		$triggerClass = $properties['TriggerClass'] ?? '';
		if (self::isCrmAutomationTrigger($triggerClass))
		{
			$additionalProperties = array_intersect_key($properties, self::getAdditionalProperties($triggerClass));
			$properties['AdditionalProperties'] = $additionalProperties;

			$returnProperties = self::transformProperties($triggerClass::toArray()['RETURN'] ?? []);
			$properties['Return'] = [...$returnProperties];
		}

		$document = static::resolveDocumentTypeFromDocument($properties['Document'] ?? null);
		if ($document)
		{
			$properties['Return']['ReturnDocument'] = static::getReturnDocumentMapType($document);
		}

		$currentActivity['Properties'] = $properties;

		return true;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		/** @var class-string<BaseTrigger> $triggerClass */
		$triggerClass = $context['Properties']['TriggerClass'] ?? $context['TriggerClass'] ?? '';
		$document = $context['Properties']['Document'] ?? $context['Document'] ?? '';
		$complexDocumentType = self::resolveDocumentTypeFromDocument($document);

		$map = [
			'TriggerClass' => [
				'Name' => '',
				'FieldName' => 'TriggerClass',
				'Type' => FieldType::STRING,
				'Required' => true,
				'Hidden' => true,
				'AllowSelection' => false,
			],
			'Document' => [
				'Name' => Loc::getMessage('BP_CRM_CRMAT_DOCUMENT'),
				'FieldName' => 'Document',
				'Type' => FieldType::DOCUMENT_TYPE,
				'Multiple' => false,
				'Options' => [],
				'Required' => false,
				'AllowSelection' => false,
				'Settings' => [
					'entity' => [
						'options' => [
							'moduleIds' => ['crm'],
							'crm' => [
								'onlyEntities' => [],
							],
						],
					],
				],
			],
			'condition' => [
				'Name' => Loc::getMessage('BP_CRM_CRMAT_CONDITION'),
				'FieldName' => 'condition',
				'Type' => FieldType::CUSTOM,
				'CustomType'=> '@trigger-condition-settings',
			],
		];

		if (!empty($complexDocumentType))
		{
			$map['condition']['Settings'] = self::getConditionSettings($complexDocumentType);
		}

		if (self::isCrmAutomationTrigger($triggerClass))
		{
			$options = static::getAvailableDocuments($triggerClass);
			$map['Document']['Options'] = $options;
			$map['Document']['Settings']['entity']['options']['crm']['onlyEntities'] = array_keys($options);

			$propertiesMap = self::getAdditionalProperties($triggerClass);
			$map = array_merge($map, $propertiesMap);
		}

		return $map;
	}

	/**
	 * @param  class-string<BaseTrigger> $triggerClass
	 */
	private static function getAdditionalProperties(string $triggerClass): array
	{
		if (!self::isCrmAutomationTrigger($triggerClass))
		{
			return [];
		}
		$trigger = $triggerClass::toArray();

		return  self::transformProperties($trigger['SETTINGS']['Properties'] ?? []);
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$fieldsMap = static::getPropertiesMap([], $arTestProperties);

		$errors = [];
		foreach ($fieldsMap as $propertyKey => $fieldProperties)
		{
			if (
				CBPHelper::getBool($fieldProperties['Required'] ?? null)
				&& CBPHelper::isEmptyValue($arTestProperties[$propertyKey])
			)
			{
				$errors[] = [
					'code' => 'NotExist',
					'parameter' => $propertyKey,
					'message' => Loc::getMessage('BP_CRM_CRMAT_EMPTY_PROP', ['#PROPERTY#' => $propertyKey]),
				];
			}
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	public function getType(): string
	{
		/** @var class-string<BaseTrigger> $triggerClass */
		$triggerClass = $this->getAllProperties()['TriggerClass'] ?? '';
		if (!self::isCrmAutomationTrigger($triggerClass))
		{
			return parent::getType();
		}

		return $triggerClass::getCode();
	}

	public function checkApplyRules(
		array $rules,
		TriggerParameters $parameters
	): Result
	{
		$properties = $this->getAllProperties();

		/** @var ?BaseTarget $target */
		$target = $parameters->get('TARGET');

		$document = $properties['Document'] ?? '';
		$triggerDocumentType = self::resolveDocumentTypeFromDocument($document);
		if (
			$triggerDocumentType
			&& (is_null($target) || !CBPHelper::isEqualDocument($target->getDocumentType(), $triggerDocumentType))
		)
		{
			return Result::createError(new Error(Loc::getMessage('BP_CRM_CRMAT_CONDITION_RULE_ERROR')));
		}

		/** @var class-string<BaseTrigger> $triggerClass */
		$triggerClass = $this->getAllProperties()['TriggerClass'];
		if ($target && self::isCrmAutomationTrigger($triggerClass))
		{
			$trigger =
				(new $triggerClass())
					->setInputData($parameters->get('INPUT_DATA'))
					->setTarget($target)
			;

			$applyRules = [
				'APPLY_RULES' => [
					'Condition' => $properties['condition'],
					...$properties['AdditionalProperties'],
				],
			];

			if (!$trigger->checkApplyRules($applyRules))
			{
				return Result::createError(new Error(Loc::getMessage('BP_CRM_CRMAT_CONDITION_RULE_ERROR')));
			}
		}

		return Result::createOk();
	}

	private static function transformProperties(array $properties): array
	{
		$activityPropertiesMap = [];
		foreach ($properties as $property)
		{
			if (array_key_exists($property['Type'], FieldType::getBaseTypesMap()))
			{
				$activityProperty = $property;
			}
			else
			{
				$activityProperty = [
					...$property,
					'Type' => FieldType::CUSTOM,
					'CustomType' => $property['Type'],
				];
			}

			$activityPropertiesMap[$property['Id']] = [
				...$activityProperty,
				'FieldName' => $property['Id'],
			];
		}

		return $activityPropertiesMap;
	}

	/**
	 * @param  class-string<BaseTrigger> $triggerClass
	 */
	private static function getAvailableDocuments(string $triggerClass): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$documents = [];

		// Factory is not returned for orders
		$typesMap = Container::getInstance()->getTypesMap();
		foreach ($typesMap->getFactories() as $factory)
		{
			$entityTypeId = $factory->getEntityTypeId();

			if(!$triggerClass::isSupported($entityTypeId))
			{
				continue;
			}

			$documentType = CCrmBizProcHelper::resolveDocumentType($entityTypeId);
			if (!$documentType)
			{
				continue;
			}

			$name = CCrmOwnerType::resolveName($entityTypeId);
			$documents[$name] = static::getDocumentName($documentType);
		}

		$orderTypeId = CCrmOwnerType::Order;
		$factory = Container::getInstance()->getFactory($orderTypeId);
		if ($factory && $triggerClass::isSupported($orderTypeId))
		{
			$orderDocumentType = CCrmBizProcHelper::resolveDocumentType($orderTypeId);
			if ($orderDocumentType)
			{
				$name = CCrmOwnerType::resolveName($orderTypeId);
				$documents[$name] = static::getDocumentName($orderDocumentType);
			}
		}

		return $documents;
	}

	private static function resolveDocumentTypeFromDocument(?string $document): ?array
	{
		if (
			!is_string($document)
			|| !str_contains($document, '@')
			|| !Loader::includeModule('crm')
		)
		{
			return null;
		}

		return explode('@', $document);
	}

	private static function getDocumentName(array $documentType)
	{
		return CBPRuntime::getRuntime()->getDocumentService()->getDocumentTypeName($documentType);
	}

	public static function getAjaxResponse($request): array
	{
		$complexDocumentType = is_string($request['document']) ? static::resolveDocumentTypeFromDocument($request['document']) : null;

		if ($complexDocumentType)
		{
			return self::getConditionSettings($complexDocumentType);
		}

		return [];
	}

	private static function getConditionSettings($complexDocumentType): array
	{
		return [
			'Fields' => array_values(Helper::getDocumentFields($complexDocumentType)),
			'DocumentType' => $complexDocumentType,
			'Title' => static::getDocumentName($complexDocumentType),
		];
	}

	private static function getReturnDocumentMapType(?array $document = null): array
	{
		return [
			'Name' =>
				$document
					? static::getDocumentName($document)
					: Loc::getMessage('BP_CRM_CRMAT_DOCUMENT')
			,
			'Type' => FieldType::DOCUMENT,
			'Default' => $document,
		];
	}

	/**
	 * @param class-string<BaseTrigger> $triggerClass
	 */
	private static function isCrmAutomationTrigger(string $triggerClass): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		return is_subclass_of($triggerClass, BaseTrigger::class);
	}
}
