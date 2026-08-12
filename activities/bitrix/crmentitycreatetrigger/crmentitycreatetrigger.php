<?php

declare(strict_types=1);

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\Public\Activity\ReturnDocumentTrait;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

if (!CBPRuntime::getRuntime()->includeActivityFile('CreateDocumentTrigger'))
{
	return;
}

class CBPCrmEntityCreateTrigger extends CBPCreateDocumentTrigger
{
	use ReturnDocumentTrait;

	private const PARAM_CATEGORY_ID = 'categoryId';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties['Document'] = '';
		$this->arProperties['ReturnDocument'] = null;
		$this->arProperties['IsAutomatedSolution'] = 'N';
		$this->arProperties[self::PARAM_CATEGORY_ID] = null;
	}

	public function execute(): int
	{
		$document = $this->getEventData()['Document'] ?? null;

		$this->setProperties([static::getReturnDocumentFieldName() => $document]);
		$this->setPropertiesTypes(static::buildReturnDocumentProperties(is_array($document) ? $document : null));

		return CBPActivityExecutionStatus::Closed;
	}

	protected static function preparePropertiesDialogValues(
		array $documentType,
		array $properties,
		array $currentValues,
	): array
	{
		$properties['Return'] = static::buildReturnDocumentProperties(
			static::resolveDocumentTypeFromDocument((string)($properties['Document'] ?? ''))
		);

		return $properties;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$document = (string)($context['Properties']['Document'] ?? $context['Document'] ?? '');
		$isAutomatedSolution = CBPHelper::getBool(
			$context['Properties']['IsAutomatedSolution'] ?? $context['IsAutomatedSolution'] ?? 'N'
		);

		$map = ['Document' => static::getDocumentPropertyMap($document, $isAutomatedSolution)];

		if ($isAutomatedSolution)
		{
			$map['IsAutomatedSolution'] = [
				'Name' => '',
				'FieldName' => 'IsAutomatedSolution',
				'Type' => FieldType::BOOL,
				'Multiple' => false,
				'Required' => false,
				'Default' => 'Y',
				'Hidden' => true,
				'AllowSelection' => false,
			];
		}

		if (static::shouldRenderCategoryField($document))
		{
			$map[self::PARAM_CATEGORY_ID] = [
				'Name' => Loc::getMessage('BP_CRM_ENTITY_CREATE_TRIGGER_CATEGORY_ID'),
				'FieldName' => self::PARAM_CATEGORY_ID,
				'Type' => FieldType::SELECT,
				'Required' => false,
				'AllowSelection' => false,
				'Options' => static::getCategoryOptions($document),
			];
		}

		return $map;
	}

	private static function getDocumentPropertyMap(string $document, bool $isAutomatedSolution): array
	{
		$map = [
			'Name' => static::getDefaultReturnDocumentTitle(),
			'FieldName' => 'Document',
			'Type' => FieldType::DOCUMENT_TYPE,
			'Multiple' => false,
			'Required' => true,
			'AllowSelection' => false,
			'Settings' => [
				'entity' => [
					'options' => [
						'moduleIds' => ['crm'],
						'crm' => ['onlyBizProcEnabled' => true],
					],
				],
			],
		];

		$type = static::resolveDocumentTypeName($document);

		if (in_array($type, static::getPresetEntityNames(), true))
		{
			$map['Hidden'] = true;
			$map['Settings']['entity']['options']['crm']['onlyEntities'] = [$type];
		}
		elseif ($isAutomatedSolution)
		{
			$map['Settings']['entity']['options']['crm']['onlyAutomatedSolution'] = true;
		}
		else
		{
			$map['Settings']['entity']['options']['crm']['onlyDynamic'] = true;
		}

		return $map;
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (\CBPHelper::isEmptyValue($arTestProperties['Document'] ?? null))
		{
			$errors[] = [
				'code' => 'Document',
				'message' => Loc::getMessage('BPFCT_DOCUMENT_EMPTY'),
			];
		}

		return array_merge($errors, CBPActivity::validateProperties($arTestProperties, $user));
	}

	public function checkApplyRules(
		array $rules,
		\Bitrix\Bizproc\Activity\Trigger\TriggerParameters $parameters,
	): \Bitrix\Bizproc\Result
	{
		$expectedEntityTypeId = static::resolveEntityTypeId($this->getActivityProperty('Document'));
		$actualEntityTypeId = static::resolveEntityTypeId($parameters->get('Document'));
		if ($expectedEntityTypeId <= 0 || $expectedEntityTypeId !== $actualEntityTypeId)
		{
			return \Bitrix\Bizproc\Result::createError(new \Bitrix\Bizproc\Error('entity type mismatch'));
		}

		$expectedCategoryId = static::normalizeCategoryId($this->getActivityProperty(self::PARAM_CATEGORY_ID));
		if ($expectedCategoryId === null)
		{
			return \Bitrix\Bizproc\Result::createOk();
		}

		$actualCategoryId = static::normalizeCategoryId(
			$parameters->get('CategoryId') ?? $parameters->get(self::PARAM_CATEGORY_ID),
		);

		return
			$expectedCategoryId === $actualCategoryId
				? \Bitrix\Bizproc\Result::createOk()
				: \Bitrix\Bizproc\Result::createError(new \Bitrix\Bizproc\Error('category mismatch'))
		;
	}

	protected function getSection(): ?Section
	{
		$entityTypeId = static::resolveEntityTypeId($this->getActivityProperty('Document'));
		if ($entityTypeId <= 0)
		{
			return null;
		}

		return new Section(
			static::getModuleId() . '|' . CCrmOwnerType::ResolveName($entityTypeId),
			($categoryId = static::normalizeCategoryId($this->getActivityProperty(self::PARAM_CATEGORY_ID))) !== null
				? (string)$categoryId
				: null,
		);
	}

	protected static function getModuleId(): string
	{
		return 'crm';
	}

	protected function getDocumentComplexType(): array
	{
		$complexType = parent::getDocumentComplexType();
		$document = $this->getRawProperty('Document');
		$documentType = static::resolveDocumentTypeFromDocument(
			$document && CBPHelper::hasStringRepresentation($document) ? (string)$document : null
		);

		return $documentType ?: $complexType;
	}

	private static function getCategoryOptions(mixed $document): array
	{
		if (!Loader::includeModule(static::getModuleId()))
		{
			return [];
		}

		$entityTypeId = static::resolveEntityTypeId($document);
		if ($entityTypeId <= 0)
		{
			return [];
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (
			!$factory
			|| !$factory->isCategoriesEnabled()
			|| !$factory->isStagesEnabled()
		)
		{
			return [];
		}

		$options = [];
		foreach ($factory->getCategories() as $category)
		{
			$options[$category->getId()] = $category->getName();
		}

		return $options;
	}

	private static function getPresetEntityNames(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		return [
			CCrmOwnerType::DealName,
			CCrmOwnerType::CompanyName,
			CCrmOwnerType::OrderName,
			CCrmOwnerType::ContactName,
			CCrmOwnerType::LeadName,
			CCrmOwnerType::QuoteName,
			CCrmOwnerType::SmartInvoiceName,
		];
	}

	private static function shouldRenderCategoryField(string $document): bool
	{
		if ($document === '')
		{
			return true;
		}

		if (!in_array(static::resolveDocumentTypeName($document), static::getPresetEntityNames(), true))
		{
			return true;
		}

		$entityTypeId = static::resolveEntityTypeId($document);
		if ($entityTypeId <= 0)
		{
			return false;
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);

		return $factory && $factory->isCategoriesEnabled() && $factory->isStagesEnabled();
	}

	private static function resolveEntityTypeId(mixed $document): int
	{
		if (is_array($document) && count($document) === 3)
		{
			[$entityTypeId] = CCrmBizProcHelper::resolveEntityId($document);

			return (int)$entityTypeId;
		}

		if (!is_string($document) || $document === '')
		{
			return 0;
		}

		$documentType = static::resolveDocumentTypeFromDocument($document);
		if (!$documentType)
		{
			return 0;
		}

		return (int)CCrmOwnerType::ResolveID((string)$documentType[2]);
	}

	private static function normalizeCategoryId(mixed $categoryId): ?int
	{
		if (is_array($categoryId))
		{
			$categoryId = reset($categoryId);
		}

		if ($categoryId === '' || $categoryId === null)
		{
			return null;
		}

		return (int)$categoryId;
	}

	protected static function resolveDocumentTypeFromDocument(?string $document): ?array
	{
		if (!$document || !Loader::includeModule('crm'))
		{
			return null;
		}

		if (str_contains($document, '@'))
		{
			return explode('@', $document);
		}

		return CCrmBizProcHelper::resolveDocumentType(CCrmOwnerType::resolveID($document));
	}

	private static function resolveDocumentTypeName(string $document): string
	{
		$complexDocumentType = static::resolveDocumentTypeFromDocument($document);

		return $complexDocumentType ? (string)$complexDocumentType[2] : $document;
	}

	public static function getAjaxResponse($request): array
	{
		$document = $request['document'] ?? null;
		if (!is_string($document) || $document === '')
		{
			return [];
		}

		return static::getCategoryOptions($document);
	}

	private function getActivityProperty(string $name): mixed
	{
		return array_key_exists($name, $this->arProperties)
			? $this->arProperties[$name]
			: $this->getRawProperty($name);
	}

	public static function getPresets(): array
	{
		Loader::includeModule('ui');

		$presets = [
			[
				'ID' => 'DEAL',
				'NAME' => Loc::getMessage('BP_CRM_DEAL_CREATE_FCT_DESCR_NAME'),
				'DESCRIPTION' => Loc::getMessage('BP_CRM_DEAL_CREATE_FCT_DESCR_DESCR'),
				'PROPERTIES' => ['Document' => 'crm@CCrmDocumentDeal@DEAL'],
				'NODE_ICON' => Outline::HANDSHAKE->name,
				'GROUPS' => [ActivityGroup::STARTER->value, ActivityGroup::SALES_CRM->value],
			],
			[
				'ID' => 'CONTACT',
				'NAME' => Loc::getMessage('BP_CRM_CONTACT_CREATE_FCT_DESCR_NAME'),
				'DESCRIPTION' => Loc::getMessage('BP_CRM_CONTACT_CREATE_FCT_DESCR_DESCR'),
				'PROPERTIES' => ['Document' => 'crm@CCrmDocumentContact@CONTACT'],
				'NODE_ICON' => Outline::CONTACT->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
					ActivityGroup::CLIENT_BASE->value,
				],
			],
			[
				'ID' => 'COMPANY',
				'NAME' => Loc::getMessage('BP_CRM_COMPANY_CREATE_FCT_DESCR_NAME'),
				'DESCRIPTION' => Loc::getMessage('BP_CRM_COMPANY_CREATE_FCT_DESCR_DESCR'),
				'PROPERTIES' => ['Document' => 'crm@CCrmDocumentCompany@COMPANY'],
				'NODE_ICON' => Outline::COMPANY->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
					ActivityGroup::CLIENT_BASE->value,
				],
			],
			[
				'ID' => 'LEAD',
				'NAME' => Loc::getMessage('BP_CRM_LEAD_CREATE_FCT_DESCR_NAME'),
				'DESCRIPTION' => Loc::getMessage('BP_CRM_LEAD_CREATE_FCT_DESCR_DESCR'),
				'PROPERTIES' => ['Document' => 'crm@CCrmDocumentLead@LEAD'],
				'NODE_ICON' => Outline::LEAD->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
					ActivityGroup::LEAD->value,
				],
			],
			[
				'ID' => 'QUOTE',
				'NAME' => Loc::getMessage('BP_CRM_QUOTE_CREATE_FCT_DESCR_NAME'),
				'DESCRIPTION' => Loc::getMessage('BP_CRM_QUOTE_CREATE_FCT_DESCR_DESCR'),
				'PROPERTIES' => ['Document' => 'crm@Bitrix\Crm\Integration\BizProc\Document\Quote@QUOTE'],
				'NODE_ICON' => Outline::SUITCASE->name,
				'GROUPS' => [ActivityGroup::STARTER->value, ActivityGroup::SALES_CRM->value],
			],
			[
				'ID' => 'DYNAMIC',
				'NAME' => Loc::getMessage('BP_CRM_DYNAMIC_CREATE_FCT_DESCR_NAME'),
				'DESCRIPTION' => Loc::getMessage('BP_CRM_DYNAMIC_CREATE_FCT_DESCR_DESCR'),
				'NODE_ICON' => Outline::SMART_PROCESS->name,
				'GROUPS' => [ActivityGroup::STARTER->value],
			],
			[
				'ID' => 'AUTOMATED_SOLUTION',
				'NAME' => Loc::getMessage('BP_CRM_AUTOMATED_SOLUTION_CREATE_FCT_DESCR_NAME'),
				'DESCRIPTION' => Loc::getMessage('BP_CRM_AUTOMATED_SOLUTION_CREATE_FCT_DESCR_DESCR'),
				'PROPERTIES' => ['IsAutomatedSolution' => 'Y'],
				'NODE_ICON' => Outline::SMART_PROCESS->name,
				'GROUPS' => [ActivityGroup::STARTER->value, ActivityGroup::DIGITAL_WORKPLACE->value],
			],
		];

		if (Loader::includeModule('crm') && \CCrmSaleHelper::isWithOrdersMode())
		{
			$presets[] = [
				'ID' => 'ORDER',
				'NAME' => Loc::getMessage('BP_CRM_ORDER_CREATE_FCT_DESCR_NAME'),
				'DESCRIPTION' => Loc::getMessage('BP_CRM_ORDER_CREATE_FCT_DESCR_DESCR'),
				'PROPERTIES' => ['Document' => 'crm@Bitrix\Crm\Integration\BizProc\Document\Order@ORDER'],
				'NODE_ICON' => Outline::CHANGE_ORDER->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
					ActivityGroup::PAYMENT->value,
				],
			];
		}

		if (Loader::includeModule('crm') && \Bitrix\Crm\Settings\InvoiceSettings::getCurrent()->isSmartInvoiceEnabled())
		{
			$presets[] = [
				'ID' => 'SMART_INVOICE',
				'NAME' => Loc::getMessage('BP_CRM_SMART_INVOICE_CREATE_FCT_DESCR_NAME'),
				'DESCRIPTION' => Loc::getMessage('BP_CRM_SMART_INVOICE_CREATE_FCT_DESCR_DESCR'),
				'PROPERTIES' => ['Document' => 'crm@Bitrix\Crm\Integration\BizProc\Document\SmartInvoice@SMART_INVOICE'],
				'NODE_ICON' => Outline::INVOICE->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
					ActivityGroup::PAYMENT->value,
				],
			];
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
		$presetId = static::getPresetIdByComplexDocumentType($complexDocumentType);

		return $presetId ? static::getPresetById($presetId) : null;
	}

	private static function getPresetIdByComplexDocumentType(array $complexDocumentType): ?string
	{
		$entityTypeId = (int)CCrmOwnerType::ResolveID((string)($complexDocumentType[2] ?? ''));

		return static::resolvePresetIdByEntityTypeId($entityTypeId);
	}

	private static function resolvePresetIdByEntityTypeId(int $entityTypeId): ?string
	{
		if (CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);

			return $factory?->isInCustomSection() ? 'AUTOMATED_SOLUTION' : 'DYNAMIC';
		}

		return match (true)
		{
			$entityTypeId === CCrmOwnerType::Deal => 'DEAL',
			$entityTypeId === CCrmOwnerType::Contact => 'CONTACT',
			$entityTypeId === CCrmOwnerType::Company => 'COMPANY',
			$entityTypeId === CCrmOwnerType::Lead => 'LEAD',
			$entityTypeId === CCrmOwnerType::Quote => 'QUOTE',
			$entityTypeId === CCrmOwnerType::Order => 'ORDER',
			$entityTypeId === CCrmOwnerType::SmartInvoice => 'SMART_INVOICE',
			default => null,
		};
	}
}
