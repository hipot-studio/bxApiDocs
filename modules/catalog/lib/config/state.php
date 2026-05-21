<?php

namespace Bitrix\Catalog\Config;

use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Iblock;
use Bitrix\Catalog;
use Bitrix\Landing;
use Bitrix\Crm;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Iblock\SectionElementTable;


/**
 * Class State
 * Provides methods for checking product restrictions and obtaining current settings based on constraints.
 *
 * @package Bitrix\Catalog\Config
 */
final class State
{
	private const EXTERNAL_CATALOG_OPTION = 'is_external_catalog';

	private const CACHE_TIME = 86400;
	private const CACHE_DIR = 'catalog/landing_limited_products/';
	private const CACHE_LIMIT_POSTFIX = '_limit';

	/** @var array */
	private static $landingSections;
	/** @var array */
	private static $iblockSections;
	/** @var array */
	private static $fullIblockSections;
	/** @var int */
	private static $elementCount;
	/** @var array */
	private static $iblockList = [];
	/** @var bool */
	private static $crmIncluded;

	/**
	 * Returns true if warehouse inventory management is allowed and enabled.
	 *
	 * @return bool
	 */
	public static function isUsedInventoryManagement(): bool
	{
		if (!Feature::checkInventoryManagementFeatureByCurrentMode())
		{
			return false;
		}

		return self::isEnabledInventoryManagement();
	}

	/**
	 * Returns true if warehouse inventory management is enabled, without feature check.
	 *
	 * @return bool
	 */
	final public static function isEnabledInventoryManagement(): bool
	{
		return (Main\Config\Option::get('catalog', 'default_use_store_control') === 'Y');
	}

	final public static function isExternalCatalog(): bool
	{
		return Main\Config\Option::get('catalog', self::EXTERNAL_CATALOG_OPTION, 'N') === 'Y';
	}

	final public static function setIsExternalCatalog(bool $isEnabled): void
	{
		Main\Config\Option::set('catalog', self::EXTERNAL_CATALOG_OPTION, $isEnabled ? 'Y' : 'N');
	}

	/**
	 * Returns true if used store quantity reserve.
	 *
	 * @return bool
	 */
	public static function isShowedStoreReserve(): bool
	{
		if (!self::isUsedInventoryManagement())
		{
			return false;
		}
		if (
			Main\Config\Option::get('catalog', 'enable_reservation') === 'Y'
			&& Main\Config\Option::get('catalog', 'show_store_reserve') === 'Y'
		)
		{
			return true;
		}
		if (self::isCrmIncluded())
		{
			return (Main\Config\Option::get('crm', 'enable_order_deal_create') === 'Y');
		}

		return false;
	}

	/**
	 * Returns true if the limit on the number of price types is exceeded.
	 *
	 * @return bool
	 */
	public static function isExceededPriceTypeLimit(): bool
	{
		if (Feature::isMultiPriceTypesEnabled())
		{
			return false;
		}

		return Catalog\GroupTable::getCount([], ['ttl' => self::CACHE_TIME]) > 1;
	}

	/**
	 * Returns true if it is allowed to add a new price type.
	 *
	 * @return bool
	 */
	public static function isAllowedNewPriceType(): bool
	{
		if (Feature::isMultiPriceTypesEnabled())
		{
			return true;
		}

		return Catalog\GroupTable::getCount([], ['ttl' => self::CACHE_TIME]) === 0;
	}

	/**
	 * Returns true if the limit on the number of warehouses is exceeded.
	 *
	 * @return bool
	 */
	public static function isExceededStoreLimit(): bool
	{
		if (Feature::isMultiStoresEnabled())
		{
			return false;
		}

		return Catalog\StoreTable::getCount([], ['ttl' => self::CACHE_TIME]) > 1;
	}

	/**
	 * Returns true if it is allowed to add a new warehouse.
	 *
	 * @return bool
	 */
	public static function isAllowedNewStore(): bool
	{
		if (Feature::isMultiStoresEnabled())
		{
			return true;
		}

		return Catalog\StoreTable::getCount([], ['ttl' => self::CACHE_TIME]) === 0;
	}

	/**
	 * Returns information about exceeding the number of goods in the landing for the information block.
	 *
	 * @param int $iblockId Iblock Id.
	 * @param int|null $sectionId Current section (can be absent).
	 * @param bool $needRealCount Used to optimize COUNT execution. Set to false if COUNT is only required for limit validation.
	 * @return array|null
	 */
	public static function getExceedingProductLimit(int $iblockId, ?int $sectionId = null, bool $needRealCount = true): ?array
	{
		if ($iblockId <= 0)
		{
			return null;
		}

		if (!ModuleManager::isModuleInstalled('bitrix24'))
		{
			return null;
		}

		if ($iblockId !== self::getCrmCatalogId())
		{
			return null;
		}

		$result = self::checkIblockLimit($iblockId, $needRealCount);
		if ($result !== null && $sectionId !== null)
		{
			self::loadIblockSections($iblockId);
			if (!isset(self::$fullIblockSections[$sectionId]))
			{
				$result = null;
			}
		}
		if ($result === null)
		{
			$result = self::getCrmCatalogLimit($iblockId);
		}

		return $result;
	}

	/**
	 * Returns information about exceeding the number of goods for crm catalog.
	 *
	 * @return array|null
	 */
	public static function getCrmExceedingProductLimit(): ?array
	{
		$crmCatalogId = self::getCrmCatalogId();
		if ($crmCatalogId > 0)
		{
			return self::getExceedingProductLimit($crmCatalogId, needRealCount: false);
		}

		return null;
	}

	/**
	 * OnIBlockElementAdd event handler. Do not use directly.
	 *
	 * @param array &$fields
	 * @return bool
	 */
	public static function handlerBeforeIblockElementAdd(array &$fields): bool
	{
		if (!self::checkIblockId($fields))
		{
			return true;
		}

		$limit = self::checkIblockLimit((int)$fields['IBLOCK_ID']);
		if (empty($limit))
		{
			return true;
		}

		if (!isset($fields['IBLOCK_SECTION']) || !is_array($fields['IBLOCK_SECTION']))
		{
			return true;
		}
		$sections = $fields['IBLOCK_SECTION'];
		Main\Type\Collection::normalizeArrayValuesByInt($sections, true);
		if (empty($sections))
		{
			return true;
		}
		self::loadIblockSections((int)$fields['IBLOCK_ID']);
		$sections = array_intersect($sections, self::$fullIblockSections);
		if (empty($sections))
		{
			return true;
		}
		unset($sections);

		self::setProductLimitError($limit['MESSAGE']);
		unset($limit);

		return false;
	}

	/**
	 * OnAfterIBlockElementAdd event handler. Do not use directly.
	 *
	 * @param array &$fields
	 * @return void
	 */
	public static function handlerAfterIblockElementAdd(array &$fields): void
	{
		if ($fields['RESULT'] === false)
			return;

		if (!self::checkIblockId($fields))
			return;

		$sections = $fields['IBLOCK_SECTION'] ?? null;
		Main\Type\Collection::normalizeArrayValuesByInt($sections, true);
		if (empty($sections))
			return;
		self::loadIblockSections((int)$fields['IBLOCK_ID']);
		$sections = array_intersect($sections, self::$fullIblockSections);
		if (empty($sections))
			return;

		self::$elementCount = null;
		self::clearCache();
	}

	/**
	 * OnBeforeIBlockElementUpdate event handler. Do not use directly.
	 *
	 * @param array &$fields
	 * @return bool
	 */
	public static function handlerBeforeIblockElementUpdate(array &$fields): bool
	{
		if (!self::checkIblockId($fields))
		{
			return true;
		}

		$limit = self::checkIblockLimit((int)$fields['IBLOCK_ID']);
		if (empty($limit))
		{
			return true;
		}

		if (!isset($fields['IBLOCK_SECTION']) || !is_array($fields['IBLOCK_SECTION']))
		{
			return true;
		}
		$sections = $fields['IBLOCK_SECTION'];
		Main\Type\Collection::normalizeArrayValuesByInt($sections, true);
		if (empty($sections))
		{
			return true;
		}
		self::loadIblockSections((int)$fields['IBLOCK_ID']);
		$sections = array_intersect($sections, self::$fullIblockSections);
		if (empty($sections))
		{
			return true;
		}
		unset($sections);

		$notMove = false;
		$iterator = Iblock\SectionElementTable::getList([
			'select' => ['IBLOCK_SECTION_ID'],
			'filter' => [
				'=IBLOCK_ELEMENT_ID' => $fields['ID'],
				'=ADDITIONAL_PROPERTY_ID' => null,
			],
		]);
		while ($row = $iterator->fetch())
		{
			$row['ID'] = (int)$row['ID'];
			if (isset(self::$fullIblockSections[$row['ID']]))
			{
				$notMove = true;
				break;
			}
		}
		unset($row, $iterator);
		if ($notMove)
		{
			return true;
		}

		self::setProductLimitError($limit['MESSAGE']);
		unset($limit);

		return false;
	}

	/**
	 * OnAfterIBlockElementUpdate event handler. Do not use directly.
	 *
	 * @param array &$fields
	 * @return void
	 */
	public static function handlerAfterIblockElementUpdate(array &$fields): void
	{
		if ($fields['RESULT'] === false)
		{
			return;
		}
		if (!self::checkIblockId($fields))
		{
			return;
		}
		if (!array_key_exists('IBLOCK_SECTION', $fields))
		{
			return;
		}

		self::$elementCount = null;
		self::clearCache();
	}

	/**
	 * OnAfterIBlockElementDelete event handler. Do not use directly.
	 *
	 * @param array $fields
	 * @return void
	 */
	public static function handlerAfterIblockElementDelete(array $fields): void
	{
		if (!self::checkIblockId($fields))
			return;

		self::$elementCount = null;
		self::clearCache();
	}

	/**
	 * OnAfterIBlockSectionAdd event handler. Do not use directly.
	 *
	 * @param array &$fields
	 * @return void
	 */
	public static function handlerAfterIblockSectionAdd(array &$fields): void
	{
		if ($fields['RESULT'] === false)
			return;
		if (!self::checkIblockId($fields))
			return;

		self::$iblockSections = null;
		self::$fullIblockSections = null;
	}

	/**
	 * OnBeforeIBlockSectionUpdate event handler. Do not use directly.
	 *
	 * @param array &$fields
	 * @return bool
	 */
	public static function handlerBeforeIblockSectionUpdate(array &$fields): bool
	{
		if (!self::checkIblockId($fields))
		{
			return true;
		}

		$limit = self::getIblockLimit((int)$fields['IBLOCK_ID']);
		if ($limit['LIMIT'] === 0)
		{
			return true;
		}
		if (!array_key_exists('IBLOCK_SECTION_ID', $fields))
		{
			return true;
		}
		$parentId = (int)$fields['IBLOCK_SECTION_ID'];
		self::loadIblockSections((int)$fields['IBLOCK_ID']);
		if (!isset(self::$fullIblockSections[$parentId]))
		{
			return true;
		}
		$iterator = Iblock\SectionTable::getList([
			'select' => ['IBLOCK_SECTION_ID'],
			'filter' => [
				'=ID' => $fields['ID'],
				'=IBLOCK_ID' => $fields['IBLOCK_ID'],
			],
		]);
		$row = $iterator->fetch();
		unset($iterator);
		if (empty($row))
		{
			return true;
		}
		$oldParentId = (int)$row['IBLOCK_SECTION_ID'];
		if (isset(self::$fullIblockSections[$oldParentId]))
		{
			return true;
		}

		$count = (int)\CIBlockElement::GetList(
			[],
			[
				'IBLOCK_ID' => $fields['IBLOCK_ID'],
				'SECTION_ID' => $fields['ID'],
				'INCLUDE_SUBSECTIONS' => 'Y',
				'CHECK_PERMISSIONS' => 'N',
			],
			[],
			false,
			['ID']
		);
		if ($count === 0)
		{
			return true;
		}
		$limit['COUNT'] += $count;
		if ($limit['COUNT'] <= $limit['LIMIT'])
		{
			return true;
		}

		$limit['MESSAGE_ID'] = 'CATALOG_STATE_ERR_PRODUCT_IN_SECTION_LIMIT';

		self::setProductLimitError(self::getProductLimitError($limit));
		unset($limit);

		return false;
	}

	/**
	 * OnAfterIBlockSectionUpdate event handler. Do not use directly.
	 *
	 * @param array &$fields
	 * @return void
	 */
	public static function handlerAfterIblockSectionUpdate(array &$fields): void
	{
		if ($fields['RESULT'] === false)
		{
			return;
		}
		if (!self::checkIblockId($fields))
		{
			return;
		}
		if (!array_key_exists('IBLOCK_SECTION_ID', $fields))
		{
			return;
		}

		self::$iblockSections = null;
		self::$fullIblockSections = null;
		self::$elementCount = null;
		self::clearCache();
	}

	/**
	 * OnAfterIBlockSectionDelete event handler. Do not use directly.
	 *
	 * @param array $fields
	 * @return void
	 */
	public static function handlerAfterIblockSectionDelete(array $fields)
	{
		if (!self::checkIblockId($fields))
			return;

		self::$iblockSections = null;
		self::$fullIblockSections = null;
		self::$elementCount = null;
		self::clearCache();
	}

	private static function clearCache(): void
	{
		Application::getInstance()->getCache()->cleanDir(self::CACHE_DIR);
	}

	private static function getUtmProductTmpEntity(): Entity
	{
		static $entity = null;
		if ($entity === null)
		{
			$entity = Entity::compileEntity(
				'UtmProductTmp',
				[
					'VALUE_ID' => ['data_type' => 'integer'],
					'FIELD_ID' => ['data_type' => 'integer'],
					'VALUE_INT' => ['data_type' => 'integer'],
				],
				['table_name' => 'b_utm_product']
			);
		}

		return $entity;
	}

	/**
	 * @param int $iblockId
	 * @param ?int $limit
	 * @return int
	 */
	private static function getElementCount(int $iblockId, ?int $limit = null): int
	{
		if (self::$elementCount === null)
		{
			self::$elementCount = 0;

			$iblockSectionIds = self::getIblockSections($iblockId);
			if (!empty($iblockSectionIds))
			{
				$areaData = Catalog\Product\SystemField\ProductMapping::getAreaData(
					Catalog\Product\SystemField\ProductMapping::MAP_LANDING,
				);
				$uniqueId = md5(serialize([$iblockSectionIds, $areaData]));
				if ($limit)
				{
					$uniqueId .= self::CACHE_LIMIT_POSTFIX;
				}
				$cache = Application::getInstance()->getCache();
				if ($cache->initCache(self::CACHE_TIME, $uniqueId, self::CACHE_DIR))
				{
					self::$elementCount = (int)$cache->getVars();
				}
				else
				{
					$subQuery = ElementTable::query()
						->setSelect(['ID'])
						->registerRuntimeField(
							(new Reference(
								'BUF1',
								self::getUtmProductTmpEntity()->getDataClass(),
								Join::on('this.ID', 'ref.VALUE_ID')
									->where('ref.FIELD_ID', $areaData['ID'])
									->where('ref.VALUE_INT', $areaData['VALUE'])
							))->configureJoinType(Join::TYPE_INNER)
						)
						->registerRuntimeField(
							(new Reference(
								'BSE',
								SectionElementTable::class,
								Join::on('this.ID', 'ref.IBLOCK_ELEMENT_ID')
							))->configureJoinType(Join::TYPE_INNER)
						)
						->registerRuntimeField(
							(new Reference(
								'BSubS',
								SectionTable::class,
								Join::on('this.BSE.IBLOCK_SECTION_ID', 'ref.ID')
							))->configureJoinType(Join::TYPE_INNER)
						)
						->registerRuntimeField(
							(new Reference(
								'BS',
								SectionTable::class,
								Join::on('this.BSubS.IBLOCK_ID', 'ref.IBLOCK_ID')
									->whereIn('ref.ID', $iblockSectionIds)
									->whereColumn('this.BSubS.LEFT_MARGIN', '>=', 'ref.LEFT_MARGIN')
									->whereColumn('this.BSubS.RIGHT_MARGIN', '<=', 'ref.RIGHT_MARGIN')
							))->configureJoinType(Join::TYPE_INNER)
						)
						->setFilter([
							'=IBLOCK_ID' => $iblockId,
							'=WF_STATUS_ID' => 1,
							'==WF_PARENT_ELEMENT_ID' => null,
						])
						->setGroup(['ID'])
					;
					if ($limit)
					{
						$subQuery->setLimit($limit);
					}

					$sql = 'SELECT COUNT(*) AS CNT FROM (' . $subQuery->getQuery() . ') t';
					self::$elementCount = (int)Application::getConnection()->queryScalar($sql);
					$cache->startDataCache();
					$cache->endDataCache(self::$elementCount);
				}
			}
		}

		return self::$elementCount;
	}

	/**
	 * @param int $iblockId
	 * @return array
	 */
	private static function getIblockSections(int $iblockId): array
	{
		if (self::$iblockSections === null)
		{
			self::loadIblockSections($iblockId);
		}
		return self::$iblockSections;
	}

	/**
	 * @param int $iblockId
	 * @return void
	 */
	private static function loadIblockSections(int $iblockId): void
	{
		if (self::$iblockSections === null)
		{
			self::$iblockSections = [];
			self::$fullIblockSections = [];
			$sections = self::getLandingSections();
			if (!empty($sections))
			{
				$iterator = Iblock\SectionTable::getList([
					'select' => [
						'ID',
						'LEFT_MARGIN',
						'RIGHT_MARGIN',
					],
					'filter' => [
						'=IBLOCK_ID' => $iblockId,
						'@ID' => $sections,
					]
				]);
				while ($row = $iterator->fetch())
				{
					$row['ID'] = (int)$row['ID'];
					self::$iblockSections[] = $row['ID'];
					self::$fullIblockSections[$row['ID']] = $row['ID'];
					$sublist = Iblock\SectionTable::getList([
						'select' => ['ID'],
						'filter' => [
							'=IBLOCK_ID' => $iblockId,
							'>LEFT_MARGIN' => $row['LEFT_MARGIN'],
							'<RIGHT_MARGIN' => $row['RIGHT_MARGIN'],
						]
					]);
					while ($sub = $sublist->fetch())
					{
						$sub['ID'] = (int)$sub['ID'];
						self::$fullIblockSections[$sub['ID']] = $sub['ID'];
					}
				}
				unset($sub, $sublist, $row, $iterator);
			}
			unset($sections);
		}
	}

	/**
	 * Returns the sections Id used in landings.
	 *
	 * @return array
	 */
	private static function getLandingSections(): array
	{
		if (self::$landingSections === null)
		{
			self::$landingSections = [];

			if (!Loader::includeModule('landing'))
			{
				return self::$landingSections;
			}

			$iterator = Landing\Internals\HookDataTable::getList([
				'runtime' => [
					new Main\ORM\Fields\Relations\Reference(
						'TMP_LANDING_SITE',
						'Bitrix\Landing\Internals\SiteTable',
						['=this.ENTITY_ID' => 'ref.ID']
					)
				],
				'select' => ['VALUE'],
				'filter' => [
					'=ENTITY_TYPE' => Landing\Hook::ENTITY_TYPE_SITE,
					'=HOOK' => 'SETTINGS',
					'=CODE' => 'SECTION_ID',
					'=TMP_LANDING_SITE.DELETED' => 'N',
				],
				'cache' => ['ttl' => self::CACHE_TIME],
			]);
			while ($row = $iterator->fetch())
			{
				$id = (int)$row['VALUE'];
				if ($id <= 0)
				{
					continue;
				}
				self::$landingSections[$id] = $id;
			}
			unset($id, $row, $iterator);

			if (!empty(self::$landingSections))
			{
				self::$landingSections = array_values(self::$landingSections);
			}
		}

		return self::$landingSections;
	}

	/**
	 * Returns crm catalog id, if exists.
	 *
	 * @return int|null
	 */
	private static function getCrmCatalogId(): ?int
	{
		$result = null;
		if (self::isCrmIncluded())
		{
			$result = Crm\Product\Catalog::getDefaultId();
		}

		return $result;
	}

	/**
	 * @param int $iblockId
	 * @return array|null
	 */
	private static function getCrmCatalogLimit(int $iblockId): ?array
	{
		if (!self::isCrmIncluded())
		{
			return null;
		}

		return Crm\Config\State::getExceedingProductLimit($iblockId);
	}

	/**
	 * Returns true if crm exists.
	 *
	 * @return bool
	 */
	private static function isCrmIncluded(): bool
	{
		if (self::$crmIncluded === null)
		{
			self::$crmIncluded = Loader::includeModule('crm');
		}

		return self::$crmIncluded;
	}

	/**
	 * Check crm catalog id.
	 *
	 * @param array $fields
	 * @return bool
	 */
	private static function checkIblockId(array $fields): bool
	{
		if (!isset($fields['IBLOCK_ID']))
		{
			return false;
		}
		$iblockId = (int)$fields['IBLOCK_ID'];
		if ($iblockId <= 0)
		{
			return false;
		}
		if (!isset(self::$iblockList[$iblockId]))
		{
			$result = true;
			if (!ModuleManager::isModuleInstalled('bitrix24'))
			{
				$result = false;
			}
			if ($iblockId !== self::getCrmCatalogId())
			{
				$result = false;
			}
			self::$iblockList[$iblockId] = $result;
		}

		return self::$iblockList[$iblockId];
	}

	/**
	 * Check products limit.
	 *
	 * @param int $iblockId
	 * @param bool $needRealCount
	 * @return array|null
	 * 	keys are case sensitive:
	 * 		<ul>
	 * 		<li>int COUNT
	 * 		<li>int LIMIT
	 * 		<li>array|null HELP_ACTION
	 * 		<li>string MESSAGE
	 * 		</ul>
	 */
	private static function checkIblockLimit(int $iblockId, bool $needRealCount = true): ?array
	{
		$result = self::getIblockLimit($iblockId, $needRealCount);
		if (
			$result['LIMIT'] === 0
			|| $result['COUNT'] < $result['LIMIT']
		)
		{
			return null;
		}
		$result['MESSAGE'] = self::getProductLimitError($result);
		unset($result['MESSAGE_ID']);
		$result['HELP_MESSAGE'] = Feature::getProductLimitHelpLink();

		return $result;
	}

	/**
	 * Returns products limit.
	 *
	 * @param int $iblockId
	 * @param bool $needRealCount
	 * @return array
	 * 	keys are case sensitive:
	 * 		<ul>
	 * 		<li>int COUNT
	 * 		<li>int LIMIT
	 * 		<li>string MESSAGE_ID
	 * 		</ul>
	 */
	private static function getIblockLimit(int $iblockId, bool $needRealCount = true): array
	{
		$result = [
			'COUNT' => 0,
			'LIMIT' => Feature::getLandingProductLimit(),
			'MESSAGE_ID' => 'CATALOG_STATE_ERR_PRODUCT_LIMIT_1'
		];
		if ($result['LIMIT'] === 0)
		{
			return $result;
		}
		$result['COUNT'] = self::getElementCount($iblockId, $needRealCount ? null : $result['LIMIT']);

		return $result;
	}

	/**
	 * Returns message with error description.
	 *
	 * @param array $limit
	 * @return string|null
	 */
	private static function getProductLimitError(array $limit): ?string
	{
		if (!isset($limit['COUNT']) || !isset($limit['LIMIT']) || !isset($limit['MESSAGE_ID']))
		{
			return null;
		}

		return Loc::getMessage(
			$limit['MESSAGE_ID'],
			[
				'#COUNT#' => $limit['COUNT'],
				'#LIMIT#' => $limit['LIMIT']
			]
		);
	}

	/**
	 * Send error.
	 *
	 * @param string $errorMessage
	 * @return void
	 */
	private static function setProductLimitError(string $errorMessage): void
	{
		global $APPLICATION;

		$error = new \CAdminException([
			[
				'text' => $errorMessage,
			]
		]);
		$APPLICATION->ThrowException($error);
	}

	/**
	 * Returns true if product card slider option is checked.
	 *
	 * @return bool
	 */
	public static function isProductCardSliderEnabled(): bool
	{
		if (!Feature::isCommonProductProcessingEnabled())
		{
			return false;
		}

		return Main\Config\Option::get('catalog', 'product_card_slider_enabled') === 'Y';
	}

	/**
	 * Returns true if product batch method calculation is selected.
	 *
	 * @return bool
	 */
	public static function isProductBatchMethodSelected(): bool
	{
		if (!Feature::isStoreBatchEnabled())
		{
			return false;
		}

		return \Bitrix\Catalog\Product\Store\CostPriceCalculator::getMethod() !== '';
	}
}
