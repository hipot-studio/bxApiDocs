<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Activity\Access\CatalogAccessChecker;
use Bitrix\Crm\Integration\BizProc\Product\ProductInfoReader;
use Bitrix\Main\Application;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class CBPCrmGetProductInfoActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const CODE = 'CrmGetProductInfoActivity';

	protected static $requiredModules = ['crm'];

	public function __construct($name)
	{
		parent::__construct($name);

		$returnProperties = self::getReturnProperties();

		$this->arProperties = [
			'Title' => '',
			'ProductId' => null,
			'ProductProperties' => [],
			'OfferProperties' => [],
			'PropertiesReturnMap' => [],
		];
		foreach (array_keys($returnProperties) as $propertyId)
		{
			$this->arProperties[$propertyId] = null;
		}

		$this->setPropertiesTypes($returnProperties);
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return self::buildPropertiesMap($dialog !== null);
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return self::buildPropertiesMap(true);
	}

	private static function buildPropertiesMap(bool $withPropertyOptions): array
	{
		$reader = self::createReader();

		return [
			'ProductId' => [
				'Name' => Loc::getMessage('CRM_BP_GPINFO_PRODUCT_ID') ?? '',
				'FieldName' => 'product_id',
				'Type' => FieldType::ENTITYSELECTOR,
				'Required' => true,
				'AllowSelection' => true,
				'Settings' => [
					'entity' => [
						'id' => 'crm_catalog_product',
						'dynamicLoad' => true,
						'dynamicSearch' => true,
					],
					'enableSearch' => true,
				],
			],
			'ProductProperties' => [
				'Name' => Loc::getMessage('CRM_BP_GPINFO_PRODUCT_PROPERTIES') ?? '',
				'FieldName' => 'product_properties',
				'Type' => FieldType::SELECT,
				'Multiple' => true,
				'Required' => false,
				'Options' => $withPropertyOptions ? self::getPropertyOptions($reader, (int)$reader->getProductIblockId()) : [],
			],
			'OfferProperties' => [
				'Name' => Loc::getMessage('CRM_BP_GPINFO_OFFER_PROPERTIES') ?? '',
				'FieldName' => 'offer_properties',
				'Type' => FieldType::SELECT,
				'Multiple' => true,
				'Required' => false,
				'Options' => $withPropertyOptions ? self::getPropertyOptions($reader, (int)$reader->getOfferIblockId()) : [],
			],
		];
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors
	): bool
	{
		if (static::checkModules() && !CatalogAccessChecker::hasAccess())
		{
			$errors = [['code' => 'AccessDenied', 'message' => CatalogAccessChecker::getErrorMessage()]];

			return false;
		}

		return parent::getPropertiesDialogValues(
			$documentType,
			$activityName,
			$workflowTemplate,
			$workflowParameters,
			$workflowVariables,
			$currentValues,
			$errors,
		);
	}

	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		$reader = self::createReader();
		$productFields = $reader->listSelectableProperties((int)$reader->getProductIblockId());
		$offerFields = $reader->listSelectableProperties((int)$reader->getOfferIblockId());

		$currentValues = $dialog->getCurrentValues();
		$currentValues['product_properties'] = self::filterSelectedIds($currentValues['product_properties'] ?? [], $productFields);
		$currentValues['offer_properties'] = self::filterSelectedIds($currentValues['offer_properties'] ?? [], $offerFields);

		$result = parent::extractPropertiesValues((clone $dialog)->setCurrentValues($currentValues), $fieldsMap);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();
		$returnMap = [];
		foreach (self::pickSelected($productFields, $data['ProductProperties']) as $propertyId => $field)
		{
			$returnMap[ProductInfoReader::PRODUCT_PROPERTY_PREFIX . $propertyId] = $field;
		}
		foreach (self::pickSelected($offerFields, $data['OfferProperties']) as $propertyId => $field)
		{
			$field['Name'] =
				Loc::getMessage('CRM_BP_GPINFO_OFFER_PROPERTY_NAME', ['#NAME#' => $field['Name']])
				?? $field['Name']
			;
			$returnMap[ProductInfoReader::OFFER_PROPERTY_PREFIX . $propertyId] = $field;
		}
		$data['PropertiesReturnMap'] = $returnMap;

		return $result->setData($data);
	}

	private static function getPropertyOptions(ProductInfoReader $reader, int $iblockId): array
	{
		return array_map(static fn(array $field) => $field['Name'], $reader->listSelectableProperties($iblockId));
	}

	private static function filterSelectedIds(mixed $selectedIds, array $fields): array
	{
		return array_values(array_intersect((array)$selectedIds, array_keys($fields)));
	}

	private static function pickSelected(array $fields, mixed $selectedIds): array
	{
		return array_intersect_key($fields, array_flip((array)$selectedIds));
	}

	protected function internalExecute(): ErrorCollection
	{
		$returnMap = $this->getRawProperty('PropertiesReturnMap');
		$returnMap = is_array($returnMap) ? $returnMap : [];
		$this->setPropertiesTypes($returnMap);

		foreach (array_keys(self::getReturnProperties() + $returnMap) as $propertyId)
		{
			$this->setProperty($propertyId);
		}

		$reader = self::createReader();
		$result = $reader->read(
			$this->getProductId(),
			self::extractPropertyIds($reader, $returnMap, ProductInfoReader::PRODUCT_PROPERTY_PREFIX),
			self::extractPropertyIds($reader, $returnMap, ProductInfoReader::OFFER_PROPERTY_PREFIX),
		);
		$runtimeTypes = $returnMap;
		if ($result->isSuccess())
		{
			$data = $result->getData();
			foreach ($data as $propertyId => $value)
			{
				$this->setProperty($propertyId, $value);
			}
			$this->setProperty('Succeeded', true);

			$runtimeTypes = $reader->resolveRuntimeTypes($returnMap, $data);
			$this->setPropertiesTypes($runtimeTypes);
		}
		else
		{
			$errorMessage = $result->getErrors()[0]->getMessage();
			$this->setProperty('Succeeded', false);
			$this->setProperty('ErrorMessage', $errorMessage);
			$this->trackError($errorMessage);
		}

		if ($this->workflow->isDebug())
		{
			$this->writeDebugInfo($this->getDebugInfo([], array_merge(self::getReturnProperties(), $runtimeTypes)));
		}

		return new ErrorCollection();
	}

	private function getProductId(): int
	{
		$productId = $this->parseValue($this->getRawProperty('ProductId'));
		if (is_array($productId))
		{
			$productId = current(CBPHelper::makeArrayFlat($productId));
		}

		return !CBPHelper::isEmptyValue($productId) && is_numeric($productId) ? (int)$productId : 0;
	}

	private static function extractPropertyIds(ProductInfoReader $reader, array $returnMap, string $prefix): array
	{
		$keys = array_filter(array_keys($returnMap), static fn($key) => str_starts_with((string)$key, $prefix));

		return array_map($reader->extractPropertyId(...), array_values($keys));
	}

	private static function createReader(): ProductInfoReader
	{
		return new ProductInfoReader(Application::getInstance()->getExceptionHandler());
	}

	private static function getReturnProperties(): array
	{
		return CBPRuntime::getRuntime()->getActivityReturnProperties(self::CODE);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}
}
