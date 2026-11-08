<?php

namespace Bitrix\Crm\Integration\BizProc\Product;

use Bitrix\Bizproc\FieldType;
use Bitrix\Catalog\ProductTable;
use Bitrix\Crm\Product\Catalog;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Collection;

final class ProductInfoReader
{
	public const ERROR_NOT_FOUND = 'PRODUCT_NOT_FOUND';
	public const ERROR_UNAVAILABLE = 'PRODUCT_UNAVAILABLE';

	public const PRODUCT_PROPERTY_PREFIX = 'PRODUCT_PROPERTY_';
	public const OFFER_PROPERTY_PREFIX = 'OFFER_PROPERTY_';

	private const VARIATION_TYPES = [ProductTable::TYPE_OFFER, ProductTable::TYPE_FREE_OFFER];
	private const ELEMENT_LINK_USER_TYPES = [
		'',
		PropertyTable::USER_TYPE_ELEMENT_LIST,
		PropertyTable::USER_TYPE_ELEMENT_AUTOCOMPLETE,
	];
	private const PROPERTY_CACHE = ['ttl' => 86400];

	public function __construct(
		private readonly ExceptionHandler $exceptionHandler,
		private readonly PropertyTypeMapper $mapper = new PropertyTypeMapper(),
	)
	{
	}

	public function getProductIblockId(): ?int
	{
		return Catalog::getDefaultId();
	}

	public function getOfferIblockId(): ?int
	{
		return Catalog::getDefaultOfferId();
	}

	public function read(int $productId, array $productPropertyIds, array $offerPropertyIds): Result
	{
		if (!Loader::includeModule('catalog'))
		{
			return $this->unavailable();
		}

		try
		{
			$result = $this->readProduct($productId);
			if ($result->isSuccess())
			{
				$product = $result->getData();
				$result->setData($product + $this->readProperties($product, $productPropertyIds, $offerPropertyIds));
			}

			return $result;
		}
		catch (\Exception $exception)
		{
			$this->exceptionHandler->writeToLog($exception);

			return $this->unavailable();
		}
	}

	public function listSelectableProperties(int $iblockId): array
	{
		if (!in_array($iblockId, [$this->getProductIblockId(), $this->getOfferIblockId()], true))
		{
			return [];
		}

		try
		{
			$fields = [];
			foreach ($this->getActiveProperties($iblockId) as $propertyId => $property)
			{
				$field = $this->mapper->toFieldProperty($property);
				if ($field !== null)
				{
					$fields[$propertyId] = $field;
				}
			}

			return $fields;
		}
		catch (\Exception $exception)
		{
			$this->exceptionHandler->writeToLog($exception);

			return [];
		}
	}

	public function resolveRuntimeTypes(array $returnMap, array $data): array
	{
		try
		{
			$propertyIds = array_filter(array_map($this->extractPropertyId(...), array_keys($returnMap)));
			$properties = $this->getPropertiesById($propertyIds);

			$namedProperties = [];
			foreach (array_keys($returnMap) as $key)
			{
				$property = $properties[$this->extractPropertyId($key)] ?? null;
				if (
					$property
					&& ($this->isElementLink($property) || $this->isList($property) || $this->isDirectory($property))
					&& !\CBPHelper::isEmptyValue($data[$key] ?? null)
				)
				{
					$namedProperties[$key] = $property;
				}
			}

			$elementNames = $this->getElementNames(array_filter($namedProperties, $this->isElementLink(...)), $data);
			$enumNames = $this->getEnumNames($this->collectValues($namedProperties, $data, $this->isList(...)));
			$directoryNames = $this->getDirectoryNames(array_filter($namedProperties, $this->isDirectory(...)), $data);

			foreach ($namedProperties as $key => $property)
			{
				$names = match (true)
				{
					$this->isElementLink($property) => $elementNames[(int)$property['LINK_IBLOCK_ID']] ?? [],
					$this->isList($property) => $enumNames,
					default => $directoryNames[$this->getDirectoryTableName($property)] ?? [],
				};

				$options = [];
				foreach ((array)$data[$key] as $value)
				{
					if (isset($names[$value]))
					{
						$options[$value] = $names[$value];
					}
				}

				$returnMap[$key] = array_merge($returnMap[$key], ['Type' => FieldType::SELECT, 'Options' => $options]);
			}
		}
		catch (\Exception $exception)
		{
			$this->exceptionHandler->writeToLog($exception);
		}

		return $returnMap;
	}

	private function collectValues(array $properties, array $data, callable $filter): array
	{
		$values = [];
		foreach (array_filter($properties, $filter) as $key => $property)
		{
			array_push($values, ...array_values((array)$data[$key]));
		}

		return $values;
	}

	private function readProduct(int $productId): Result
	{
		if ($productId <= 0)
		{
			return $this->notFound();
		}

		$product = ProductTable::getRow([
			'select' => ['ID', 'TYPE', 'MEASURE'],
			'filter' => ['=ID' => $productId],
		]);
		if (!$product)
		{
			return $this->notFound();
		}

		$type = (int)$product['TYPE'];
		$isVariation = in_array($type, self::VARIATION_TYPES, true);

		$element = \CIBlockElement::GetList(
			[],
			['ID' => $productId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			[
				'ID',
				'IBLOCK_ID',
				'NAME',
				'ACTIVE',
				'DETAIL_TEXT',
				'IBLOCK_SECTION_ID',
				'PREVIEW_PICTURE',
				'DETAIL_PICTURE',
			],
		)->Fetch();
		if (!$element)
		{
			return $this->notFound();
		}

		$iblockId = (int)$element['IBLOCK_ID'];
		$expectedIblockId = $isVariation ? $this->getOfferIblockId() : $this->getProductIblockId();
		if ($iblockId !== $expectedIblockId)
		{
			return $this->unavailable();
		}

		$sectionId = (int)$element['IBLOCK_SECTION_ID'];
		$measure = $this->getMeasure((int)$product['MEASURE']);

		return (new Result())->setData([
			'Id' => $productId,
			'IsVariation' => $isVariation,
			'ParentId' => $type === ProductTable::TYPE_OFFER ? $this->getParentId($productId, $iblockId) : null,
			'Name' => (string)$element['NAME'],
			'Type' => $type,
			'Active' => $element['ACTIVE'] === 'Y',
			'Description' => (string)$element['DETAIL_TEXT'],
			'SectionId' => $sectionId > 0 ? $sectionId : null,
			'SectionName' => $this->getSectionName($sectionId),
			'MeasureName' => (string)($measure['MEASURE_TITLE'] ?? ''),
			'MeasureSymbol' => (string)($measure['SYMBOL_RUS'] ?? ''),
			'Images' => $this->collectImages($element),
		]);
	}

	private function readProperties(array $product, array $productPropertyIds, array $offerPropertyIds): array
	{
		$productElementId = $product['IsVariation'] ? $product['ParentId'] : $product['Id'];
		$offerElementId = $product['IsVariation'] ? $product['Id'] : null;

		return
			$this->readPropertyValues(
				self::PRODUCT_PROPERTY_PREFIX,
				(int)$this->getProductIblockId(),
				$productElementId,
				$productPropertyIds,
			)
			+ $this->readPropertyValues(
				self::OFFER_PROPERTY_PREFIX,
				(int)$this->getOfferIblockId(),
				$offerElementId,
				$offerPropertyIds,
			)
		;
	}

	private function readPropertyValues(string $prefix, int $iblockId, ?int $elementId, array $propertyIds): array
	{
		Collection::normalizeArrayValuesByInt($propertyIds, false);

		$properties = $elementId
			? array_intersect_key($this->getActiveProperties($iblockId), array_flip($propertyIds))
			: [];

		// GetPropertyValuesArray would apply ConvertFromDB, and the mapper expects raw database values
		$rawValues = $properties
			? \CIBlockElement::GetPropertyValues(
				$iblockId,
				['ID' => $elementId],
				true,
				['ID' => array_keys($properties)],
			)->Fetch()
			: [];

		$values = [];
		foreach ($propertyIds as $propertyId)
		{
			$values[$prefix . $propertyId] = isset($properties[$propertyId])
				? $this->mapper->toValue($properties[$propertyId], $rawValues[$propertyId] ?? null)
				: null;
		}

		return $values;
	}

	private function getActiveProperties(int $iblockId): array
	{
		if ($iblockId <= 0 || !Loader::includeModule('iblock'))
		{
			return [];
		}

		$iterator = PropertyTable::getList([
			'select' => ['ID', 'NAME', 'PROPERTY_TYPE', 'USER_TYPE', 'MULTIPLE'],
			'filter' => ['=IBLOCK_ID' => $iblockId, '=ACTIVE' => 'Y'],
			'order' => ['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
			'cache' => self::PROPERTY_CACHE,
		]);

		$properties = [];
		while ($property = $iterator->fetch())
		{
			$properties[(int)$property['ID']] = $property;
		}

		return $properties;
	}

	private function getEnumNames(array $enumIds): array
	{
		Collection::normalizeArrayValuesByInt($enumIds, false);
		if (!$enumIds)
		{
			return [];
		}

		$iterator = PropertyEnumerationTable::getList([
			'select' => ['ID', 'VALUE'],
			'filter' => ['@ID' => $enumIds],
			'cache' => self::PROPERTY_CACHE,
		]);

		$names = [];
		while ($enum = $iterator->fetch())
		{
			$names[(int)$enum['ID']] = (string)$enum['VALUE'];
		}

		return $names;
	}

	private function getPropertiesById(array $propertyIds): array
	{
		if (!$propertyIds || !Loader::includeModule('iblock'))
		{
			return [];
		}

		$iterator = PropertyTable::getList([
			'select' => ['ID', 'PROPERTY_TYPE', 'USER_TYPE', 'USER_TYPE_SETTINGS_LIST', 'LINK_IBLOCK_ID'],
			'filter' => ['@ID' => $propertyIds],
			'cache' => self::PROPERTY_CACHE,
		]);

		$properties = [];
		while ($property = $iterator->fetch())
		{
			$properties[(int)$property['ID']] = $property;
		}

		return $properties;
	}

	public function extractPropertyId(string $key): ?int
	{
		foreach ([self::PRODUCT_PROPERTY_PREFIX, self::OFFER_PROPERTY_PREFIX] as $prefix)
		{
			if (str_starts_with($key, $prefix))
			{
				return (int)substr($key, strlen($prefix));
			}
		}

		return null;
	}

	private function isElementLink(array $property): bool
	{
		return
			$property['PROPERTY_TYPE'] === PropertyTable::TYPE_ELEMENT
			&& in_array((string)$property['USER_TYPE'], self::ELEMENT_LINK_USER_TYPES, true)
		;
	}

	private function isList(array $property): bool
	{
		return $property['PROPERTY_TYPE'] === PropertyTable::TYPE_LIST;
	}

	private function isDirectory(array $property): bool
	{
		return
			$property['PROPERTY_TYPE'] === PropertyTable::TYPE_STRING
			&& $property['USER_TYPE'] === PropertyTable::USER_TYPE_DIRECTORY
			&& Loader::includeModule('highloadblock')
		;
	}

	private function getElementNames(array $properties, array $data): array
	{
		$idsByLinkIblock = [];
		foreach ($properties as $key => $property)
		{
			$linkIblockId = (int)$property['LINK_IBLOCK_ID'];
			$idsByLinkIblock[$linkIblockId] = array_merge(
				$idsByLinkIblock[$linkIblockId] ?? [],
				array_values((array)$data[$key]),
			);
		}

		$names = [];
		foreach ($idsByLinkIblock as $linkIblockId => $elementIds)
		{
			Collection::normalizeArrayValuesByInt($elementIds, false);
			if (!$elementIds)
			{
				continue;
			}

			$filter = ['@ID' => $elementIds];
			if ($linkIblockId > 0)
			{
				$filter['=IBLOCK_ID'] = $linkIblockId;
			}

			$iterator = ElementTable::getList([
				'select' => ['ID', 'NAME'],
				'filter' => $filter,
			]);
			while ($element = $iterator->fetch())
			{
				$names[$linkIblockId][(int)$element['ID']] = (string)$element['NAME'];
			}
		}

		return $names;
	}

	private function getDirectoryNames(array $properties, array $data): array
	{
		$xmlIdsByTable = [];
		foreach ($properties as $key => $property)
		{
			$tableName = $this->getDirectoryTableName($property);
			$xmlIdsByTable[$tableName] = array_merge($xmlIdsByTable[$tableName] ?? [], array_values((array)$data[$key]));
		}

		$names = [];
		foreach ($xmlIdsByTable as $tableName => $xmlIds)
		{
			$records = \CIBlockPropertyDirectory::GetExtendedValue(
				['USER_TYPE_SETTINGS' => ['TABLE_NAME' => $tableName]],
				['VALUE' => array_values(array_unique($xmlIds))],
			);
			$names[$tableName] = array_column(array_filter((array)$records), 'VALUE', 'UF_XML_ID');
		}

		return $names;
	}

	private function getDirectoryTableName(array $property): string
	{
		return (string)($property['USER_TYPE_SETTINGS_LIST']['TABLE_NAME'] ?? '');
	}

	private function getParentId(int $productId, int $iblockId): ?int
	{
		$parent = \CCatalogSku::GetProductInfo($productId, $iblockId);

		return $parent ? (int)$parent['ID'] : null;
	}

	private function getSectionName(int $sectionId): string
	{
		if ($sectionId <= 0)
		{
			return '';
		}

		$section = \CIBlockSection::GetList(
			[],
			['ID' => $sectionId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['ID', 'NAME'],
		)->Fetch();

		return $section ? (string)$section['NAME'] : '';
	}

	private function getMeasure(int $measureId): ?array
	{
		// CCatalogMeasure fills the empty titles of classifier measures, MeasureTable does not
		$measure = $measureId > 0
			? \CCatalogMeasure::getList(
				[],
				['=ID' => $measureId],
				false,
				false,
				['ID', 'CODE', 'MEASURE_TITLE', 'SYMBOL_RUS', 'SYMBOL_INTL'],
			)->Fetch()
			: false;
		if ($measure)
		{
			return $measure;
		}

		$defaultMeasure = \CCatalogMeasure::getDefaultMeasure(true, true);

		return $defaultMeasure
			? ['MEASURE_TITLE' => $defaultMeasure['~MEASURE_TITLE'], 'SYMBOL_RUS' => $defaultMeasure['~SYMBOL_RUS']]
			: null;
	}

	private function collectImages(array $element): array
	{
		$imageIds = [(int)$element['PREVIEW_PICTURE'], (int)$element['DETAIL_PICTURE']];

		$morePhoto = \CIBlockElement::GetProperty(
			(int)$element['IBLOCK_ID'],
			(int)$element['ID'],
			[],
			['CODE' => \CIBlockPropertyTools::CODE_MORE_PHOTO],
		);
		while ($value = $morePhoto->Fetch())
		{
			$imageIds[] = (int)$value['VALUE'];
		}

		return array_values(array_unique(array_filter($imageIds, static fn(int $id) => $id > 0)));
	}

	private function notFound(): Result
	{
		return $this->error(
			Loc::getMessage('CRM_BP_PRODUCT_INFO_READER_ERROR_NOT_FOUND') ?? '',
			self::ERROR_NOT_FOUND,
		);
	}

	private function unavailable(): Result
	{
		return $this->error(
			Loc::getMessage('CRM_BP_PRODUCT_INFO_READER_ERROR_UNAVAILABLE') ?? '',
			self::ERROR_UNAVAILABLE,
		);
	}

	private function error(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
