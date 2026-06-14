<?php

declare(strict_types=1);

use Bitrix\Bizproc\Activity\Mixins\ManualStartDocumentTrait;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
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

class CBPCrmSmartManualStartTrigger extends \CBPManualStartTrigger
{
	use ManualStartDocumentTrait;

	private const PARAM_CATEGORY_ID = 'categoryId';
	private const PARAM_SMART_TYPE_ID = 'smartTypeId';
	private const PARAM_ONLY_AUTOMATED_SOLUTION = 'onlyAutomatedSolution';
	private const CRM_SMART_CATEGORY_SELECT = 'crm-smart-category-select';
	private const CATEGORIES_BY_SMART_TYPE = 'categoriesBySmartType';
	private const DYNAMIC_TYPE_PREFIX = 'DYNAMIC_';

	public function __construct($name)
	{
		parent::__construct($name);
		$this->initManualStartDocumentProperties();

		$this->arProperties[self::PARAM_CATEGORY_ID] = null;
		$this->arProperties[self::PARAM_SMART_TYPE_ID] = null;
		$this->arProperties[self::PARAM_ONLY_AUTOMATED_SOLUTION] = 'N';
		$this->setPropertiesTypes([
			self::PARAM_SMART_TYPE_ID => [
				'Type' => FieldType::SELECT,
			],
			self::PARAM_CATEGORY_ID => [
				'Type' => FieldType::CUSTOM,
				'CustomType' => self::CRM_SMART_CATEGORY_SELECT,
			],
			self::PARAM_ONLY_AUTOMATED_SOLUTION => [
				'Type' => FieldType::BOOL,
			],
		]);
	}

	protected function getReturnDocumentMapTypeForInstance(): array
	{
		$smartTypeId = $this->getRawProperty(self::PARAM_SMART_TYPE_ID);

		return static::getReturnDocumentMapType($smartTypeId);
	}

	protected static function getReturnDocumentMapType(?string $smartTypeId = null): array
	{
		if (!$smartTypeId)
		{
			return [
				'Name' => Loc::getMessage('BP_CRM_FCT_DOCUMENT'),
				'Type' => FieldType::DOCUMENT,
				'Default' => null,
			];
		}

		$document = static::resolveDocumentType($smartTypeId);

		return [
			'Name' =>
				$document
					? static::getDocumentName($document)
					: (Loc::getMessage('BP_CRM_FCT_DOCUMENT') ?? '')
			,
			'Type' => FieldType::DOCUMENT,
			'Default' => $document,
		];
	}

	protected static function getDocumentName(array $documentType)
	{
		return CBPRuntime::getRuntime()->getDocumentService()->getDocumentTypeName($documentType);
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

		$properties['Return'] = [
			static::getReturnDocumentFieldName() => static::getReturnDocumentMapType($currentValues[self::PARAM_SMART_TYPE_ID] ?? null),
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
				$context[self::PARAM_ONLY_AUTOMATED_SOLUTION]
				?? $context['Properties'][self::PARAM_ONLY_AUTOMATED_SOLUTION]
				?? 'N'
			) === 'Y';
		[$smartList, $categoryList, $groups] = self::getSmartProcessList($onlyAutomatedSolution);

		return [
			self::PARAM_SMART_TYPE_ID => [
				'Name' => Loc::getMessage('BP_CRM_DEAL_MANUAL_START_TRIGGER_FIELD_SMART_TYPE'),
				'FieldName' => self::PARAM_SMART_TYPE_ID,
				'Type' => FieldType::SELECT,
				'Options' => $smartList,
				'AllowSelection' => false,
				'Required' => true,
				'Settings' => [
					'Groups' => array_values($groups),
				]
			],
			self::PARAM_CATEGORY_ID => [
				'Name' => Loc::getMessage('BP_CRM_DEAL_MANUAL_START_TRIGGER_FIELD_CATEGORY_ID'),
				'FieldName' => self::PARAM_CATEGORY_ID,
				'Type' => FieldType::CUSTOM,
				'CustomType' => self::CRM_SMART_CATEGORY_SELECT,
				'Settings' => [
					self::CATEGORIES_BY_SMART_TYPE => $categoryList,
				],
			],
			self::PARAM_ONLY_AUTOMATED_SOLUTION => [
				'FieldName' => self::PARAM_ONLY_AUTOMATED_SOLUTION,
				'Type' => FieldType::BOOL,
				'Default' => $onlyAutomatedSolution ? 'Y' : 'N',
				'Hidden' => true,
			],
		];
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$arErrors = [];

		if (empty($arTestProperties[self::PARAM_SMART_TYPE_ID]))
		{
			$arErrors[] = [
				'code' => 'NotExist',
				'parameter' => self::PARAM_SMART_TYPE_ID,
				'message' => Loc::getMessage('BP_CRM_DEAL_MANUAL_START_TRIGGER_FIELD_SMART_TYPE_ID_EMPTY'),
			];
		}

		return array_merge($arErrors, parent::ValidateProperties($arTestProperties, $user));
	}

	protected static function getSmartProcessList(bool $onlyAutomatedSolution): array
	{
		$dynamicTypesMap = Container::getInstance()->getDynamicTypesMap();
		$dynamicTypesMap->load([
			'isLoadCategories' => false,
			'isLoadStages' => false,
		]);

		$smartList = [];
		$categories = [];
		$groups = [];
		foreach ($dynamicTypesMap->getTypes() as $type)
		{
			if (!$type->getIsBizProcEnabled())
			{
				continue;
			}
			$isAutomatedSolution = (int)$type->getCustomSectionId() !== 0;

			$smartKey = self::DYNAMIC_TYPE_PREFIX . $type->getEntityTypeId();
			if ($isAutomatedSolution && $onlyAutomatedSolution)
			{
				$automatedSolution = Container::getInstance()->getAutomatedSolutionManager()->getAutomatedSolution(
					$type->getCustomSectionId(),
				);
				$groupName = $automatedSolution['TITLE'];

				$groups[$groupName]['name'] ??= $groupName;
				$groups[$groupName]['items'][$smartKey] = $type->getTitle();
			}

			if ($isAutomatedSolution === $onlyAutomatedSolution)
			{
				$smartList[$smartKey] = $type->getTitle();

				if ($type->getIsCategoriesEnabled())
				{
					$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($type->getEntityTypeId());

					foreach ($factory->getCategories() as $category)
					{
						$categories[$smartKey][$category->getId()] = $category->getName();
					}
				}
			}
		}

		return [
			$smartList,
			$categories,
			$groups,
		];
	}

	public function getSection(): ?Section
	{
		try
		{
			$category = $this->{self::PARAM_CATEGORY_ID};
		}
		catch (\Throwable)
		{
			$category = null;
		}

		return new Section(
			static::getModuleId() . '|' . $this->{self::PARAM_SMART_TYPE_ID},
			$category,
		);
	}

	protected static function getModuleId(): string
	{
		return 'crm';
	}

	protected static function resolveDocumentType(?string $entityTypeId = null): ?array
	{
		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return null;
		}

		if (!$entityTypeId)
		{
			return null;
		}

		$documentType = CCrmBizProcHelper::ResolveDocumentType(CCrmOwnerType::resolveID($entityTypeId));

		return $documentType ?? null;
	}

	protected function getDocumentComplexType(): array
	{
		$complexType = parent::getDocumentComplexType();

		$entityTypeId = $this->getRawProperty(self::PARAM_SMART_TYPE_ID);
		if ($entityTypeId)
		{
			return static::resolveDocumentType($entityTypeId) ?? [];
		}

		return $complexType;
	}
}
