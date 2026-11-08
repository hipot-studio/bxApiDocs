<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Trigger;

use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\Activity\Trigger\TriggerParameters;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Public\Entity\Trigger\Section;
use Bitrix\Bizproc\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

/**
 * Reusable CRM wrapping shared by node-workflow entity triggers
 * (CBPCrmEntityCreateTrigger, CBPCrmEntityEditTrigger): document/category
 * properties, presets, section binding, applicability check by type/category
 * and the full CRM complex type. Consumers must also `use ReturnDocumentTrait`.
 */
trait CrmEntityTriggerTrait
{
	private const CATEGORY_ID_PROPERTY = 'categoryId';

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
		return static::getDocumentPropertiesMap($context);
	}

	protected static function getDocumentPropertiesMap(array $context): array
	{
		$document = (string)($context['Properties']['Document'] ?? $context['Document'] ?? '');
		$isAutomatedSolution = \CBPHelper::getBool(
			$context['Properties']['IsAutomatedSolution'] ?? $context['IsAutomatedSolution'] ?? 'N'
		);

		$map = ['Document' => static::getDocumentPropertyMap($document, $isAutomatedSolution)];

		if ($isAutomatedSolution)
		{
			$map['IsAutomatedSolution'] = static::buildAutomatedSolutionPropertyMap();
		}

		$map += CategoryProperty::buildPropertyMap($document);

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

		$type = CategoryProperty::resolveDocumentTypeName($document);

		if (in_array($type, CategoryProperty::getPresetEntityNames(), true))
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

	private static function buildAutomatedSolutionPropertyMap(): array
	{
		return [
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

	public static function validateProperties($arTestProperties = [], \CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (\CBPHelper::isEmptyValue($arTestProperties['Document'] ?? null))
		{
			$errors[] = [
				'code' => 'Document',
				'message' => Loc::getMessage('BPFCT_DOCUMENT_EMPTY'),
			];
		}

		return array_merge($errors, \CBPActivity::validateProperties($arTestProperties, $user));
	}

	public function checkApplyRules(
		array $rules,
		TriggerParameters $parameters,
	): Result
	{
		$expectedEntityTypeId = CategoryProperty::resolveEntityTypeId($this->getActivityProperty('Document'));
		$actualEntityTypeId = CategoryProperty::resolveEntityTypeId($parameters->get('Document'));
		if ($expectedEntityTypeId <= 0 || $expectedEntityTypeId !== $actualEntityTypeId)
		{
			return Result::createError(new Error('entity type mismatch'));
		}

		$expectedCategoryIds = CategoryProperty::normalizeIds($this->getActivityProperty(self::CATEGORY_ID_PROPERTY));
		if ($expectedCategoryIds === [])
		{
			return Result::createOk();
		}

		$actualCategoryId = CategoryProperty::normalizeId(
			$parameters->get('CategoryId') ?? $parameters->get(self::CATEGORY_ID_PROPERTY),
		);

		return
			in_array($actualCategoryId, $expectedCategoryIds, true)
				? Result::createOk()
				: Result::createError(new Error('category mismatch'))
		;
	}

	protected function getSection(): ?Section
	{
		$entityTypeId = CategoryProperty::resolveEntityTypeId($this->getActivityProperty('Document'));
		if ($entityTypeId <= 0)
		{
			return null;
		}

		return new Section(
			static::getModuleId() . '|' . \CCrmOwnerType::ResolveName($entityTypeId),
			($categoryId = CategoryProperty::normalizeId($this->getActivityProperty(self::CATEGORY_ID_PROPERTY))) !== null
				? (string)$categoryId
				: null,
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

		$categoryIds = CategoryProperty::normalizeIds($this->getActivityProperty(self::CATEGORY_ID_PROPERTY));
		if ($categoryIds === [])
		{
			return [$this->getSection()];
		}

		$sectionId = static::getModuleId() . '|' . \CCrmOwnerType::ResolveName($entityTypeId);

		return array_map(
			static fn(int $categoryId): Section => new Section($sectionId, (string)$categoryId),
			$categoryIds,
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
			$document && \CBPHelper::hasStringRepresentation($document) ? (string)$document : null
		);

		return $documentType ?: $complexType;
	}

	protected static function resolveDocumentTypeFromDocument(?string $document): ?array
	{
		return CategoryProperty::resolveDocumentTypeFromDocument($document);
	}

	public static function getAjaxResponse($request): array
	{
		$document = $request['document'] ?? null;
		if (!is_string($document) || $document === '')
		{
			return [];
		}

		return CategoryProperty::getOptions($document);
	}

	private function getActivityProperty(string $name): mixed
	{
		return array_key_exists($name, $this->arProperties)
			? $this->arProperties[$name]
			: $this->getRawProperty($name);
	}

	/**
	 * Phrase-code template of the presets: the trait is shared by the create and the edit trigger, and
	 * Loc stores messages flat per language: a shared code would make the lang file loaded last
	 * overwrite the preset titles of the other trigger.
	 */
	protected static function getPresetMessagePrefix(): string
	{
		return 'BP_CRM_%s_CREATE_FCT_DESCR';
	}

	private static function getPresetMessage(string $entityCode, string $suffix): ?string
	{
		return Loc::getMessage(sprintf(static::getPresetMessagePrefix(), $entityCode) . '_' . $suffix);
	}

	public static function getPresets(): array
	{
		Loader::includeModule('ui');

		$presets = [
			[
				'ID' => 'DEAL',
				'NAME' => static::getPresetMessage('DEAL', 'NAME'),
				'DESCRIPTION' => static::getPresetMessage('DEAL', 'DESCR'),
				'PROPERTIES' => ['Document' => 'crm@CCrmDocumentDeal@DEAL'],
				'NODE_ICON' => Outline::HANDSHAKE->name,
				'GROUPS' => [ActivityGroup::STARTER->value, ActivityGroup::SALES_CRM->value],
			],
			[
				'ID' => 'CONTACT',
				'NAME' => static::getPresetMessage('CONTACT', 'NAME'),
				'DESCRIPTION' => static::getPresetMessage('CONTACT', 'DESCR'),
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
				'NAME' => static::getPresetMessage('COMPANY', 'NAME'),
				'DESCRIPTION' => static::getPresetMessage('COMPANY', 'DESCR'),
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
				'NAME' => static::getPresetMessage('LEAD', 'NAME'),
				'DESCRIPTION' => static::getPresetMessage('LEAD', 'DESCR'),
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
				'NAME' => static::getPresetMessage('QUOTE', 'NAME'),
				'DESCRIPTION' => static::getPresetMessage('QUOTE', 'DESCR'),
				'PROPERTIES' => ['Document' => 'crm@Bitrix\Crm\Integration\BizProc\Document\Quote@QUOTE'],
				'NODE_ICON' => Outline::SUITCASE->name,
				'GROUPS' => [ActivityGroup::STARTER->value, ActivityGroup::SALES_CRM->value],
			],
			[
				'ID' => 'DYNAMIC',
				'NAME' => static::getPresetMessage('DYNAMIC', 'NAME'),
				'DESCRIPTION' => static::getPresetMessage('DYNAMIC', 'DESCR'),
				'NODE_ICON' => Outline::SMART_PROCESS->name,
				'GROUPS' => [ActivityGroup::STARTER->value],
			],
			[
				'ID' => 'AUTOMATED_SOLUTION',
				'NAME' => static::getPresetMessage('AUTOMATED_SOLUTION', 'NAME'),
				'DESCRIPTION' => static::getPresetMessage('AUTOMATED_SOLUTION', 'DESCR'),
				'PROPERTIES' => ['IsAutomatedSolution' => 'Y'],
				'NODE_ICON' => Outline::SMART_PROCESS->name,
				'GROUPS' => [ActivityGroup::STARTER->value, ActivityGroup::DIGITAL_WORKPLACE->value],
			],
		];

		if (Loader::includeModule('crm') && \CCrmSaleHelper::isWithOrdersMode())
		{
			$presets[] = [
				'ID' => 'ORDER',
				'NAME' => static::getPresetMessage('ORDER', 'NAME'),
				'DESCRIPTION' => static::getPresetMessage('ORDER', 'DESCR'),
				'PROPERTIES' => ['Document' => 'crm@Bitrix\Crm\Integration\BizProc\Document\Order@ORDER'],
				'NODE_ICON' => Outline::CHANGE_ORDER->name,
				'GROUPS' => [
					ActivityGroup::STARTER->value,
					ActivityGroup::SALES_CRM->value,
					ActivityGroup::PAYMENT->value,
				],
			];
		}

		if (Loader::includeModule('crm') && InvoiceSettings::getCurrent()->isSmartInvoiceEnabled())
		{
			$presets[] = [
				'ID' => 'SMART_INVOICE',
				'NAME' => static::getPresetMessage('SMART_INVOICE', 'NAME'),
				'DESCRIPTION' => static::getPresetMessage('SMART_INVOICE', 'DESCR'),
				'PROPERTIES' => ['Document' => 'crm@Bitrix\Crm\Integration\BizProc\Document\SmartInvoice@SMART_INVOICE'],
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
			$document = static::resolveDocumentTypeFromDocument($preset['PROPERTIES']['Document'] ?? null);
			if ($document === null)
			{
				continue;
			}

			$presets[$index]['PROPERTIES'] = static::preparePropertiesDialogValues(
				$document,
				$preset['PROPERTIES'] ?? [],
				[],
			);
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
		$entityTypeId = (int)\CCrmOwnerType::ResolveID((string)($complexDocumentType[2] ?? ''));

		return static::resolvePresetIdByEntityTypeId($entityTypeId);
	}

	private static function resolvePresetIdByEntityTypeId(int $entityTypeId): ?string
	{
		if (\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);

			return $factory?->isInCustomSection() ? 'AUTOMATED_SOLUTION' : 'DYNAMIC';
		}

		return match (true)
		{
			$entityTypeId === \CCrmOwnerType::Deal => 'DEAL',
			$entityTypeId === \CCrmOwnerType::Contact => 'CONTACT',
			$entityTypeId === \CCrmOwnerType::Company => 'COMPANY',
			$entityTypeId === \CCrmOwnerType::Lead => 'LEAD',
			$entityTypeId === \CCrmOwnerType::Quote => 'QUOTE',
			$entityTypeId === \CCrmOwnerType::Order => 'ORDER',
			$entityTypeId === \CCrmOwnerType::SmartInvoice => 'SMART_INVOICE',
			default => null,
		};
	}
}
