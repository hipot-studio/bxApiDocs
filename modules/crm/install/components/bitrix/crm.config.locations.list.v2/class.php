<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage crm
 * @copyright 2001-2014 Bitrix
 */

use Bitrix\Main\Config;
use Bitrix\Main;
use Bitrix\Crm\V2\Infrastructure\Component\Location\Filter\LocationDataProvider;
use Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\LocationGrid;
use Bitrix\Crm\V2\Internal\Integration\Sale\Location\LocationAdapter;
use Bitrix\Crm\V2\Internal\Service\Location\LocationListService;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Filter\Component\ComponentParams as FilterComponentParams;
use Bitrix\Main\Grid\Component\ComponentParams as GridComponentParams;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Filter\Options as FilterOptions;

Loc::loadMessages(__FILE__);

class CBitrixCrmConfigLocationList2Component extends CBitrixComponent
{
	private const GRID_ID = 'CRM_LOC_LIST';
	private const BATCH_CONTROLLER = 'crm.v2.Location.BatchDelete';

	protected $componentData = 	[];
	protected $dbResult = 		[];
	protected $errors = 		['FATAL' => [], 'NONFATAL' => []];

	protected $currentCache = 	false;
	private ?LocationAdapter $locationAdapter = null;
	private ?LocationListService $locationListService = null;
	private ?LocationGrid $grid = null;
	private ?Filter $filter = null;

	/**
	 * Function checks and prepares all the parameters passed. Everything about $arParam modification is here.
	 * @param mixed[] $arParams List of unchecked parameters
	 * @return mixed[] Checked and valid parameters
	 */
	public function onPrepareComponentParams($arParams)
	{
		$arParams['PARENT_ID'] ??= 0;
		$arParams['PARENT_ID_FROM_REQUEST'] ??= 'N';
		self::tryParseInt($arParams['CACHE_TIME'], 36000000, true);
		self::tryParseInt($arParams['PARENT_ID'], 0, true);
		self::tryParseBoolean($arParams['PARENT_ID_FROM_REQUEST']);

		// parse function examples:

		//self::tryParseInt($arParams['ID']);
		//self::tryParseString($arParams['CODE']);
		//self::tryParseStringStrict($arParams['JS_CONTROL_GLOBAL_ID']);
		//self::tryParseWhiteList($arParams['PROVIDE_LINK_BY'], array('id', 'code'));
		//self::tryParseBoolean($arParams['SEARCH_BY_PRIMARY']);

		// some more custom parsing here

		return $arParams;
	}

	/**
	 * Function checks if required modules installed. If not, throws an exception
	 * @throws Exception
	 * @return void
	 */
	protected function checkRequiredModules()
	{
		$result = true;

		if(!Loader::includeModule('crm'))
		{
			$this->errors['FATAL'][] = Loc::getMessage("CRM_CLL2_CRM_MODULE_NOT_INSTALL");
			$result = false;
		}

		if(!Loader::includeModule('sale'))
		{
			$this->errors['FATAL'][] = Loc::getMessage("CRM_CLL2_SALE_MODULE_NOT_INSTALL");
			$result = false;
		}

		return $result;
	}

	/**
	 * Function checks if user have basic permissions to launch the component
	 * @throws Exception
	 * @return void
	 */
	protected function checkPermissions()
	{
		$result = true;

		$this->dbResult['CAN_DELETE'] = $this->dbResult['CAN_EDIT'] = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->isCrmAdmin();
		if(!$this->dbResult['CAN_EDIT'])
		{
			$this->errors['FATAL'][] = Loc::getMessage('CRM_CLL2_PERMISSION_DENIED');
			$result = false;
		}

		return $result;
	}

	/**
	 * Additional parameters check, if we have parameters depend on modules included.
	 * @return void
	 */
	protected function checkParameters()
	{
		$this->arParams['PATH_TO_LOCATIONS_LIST'] = CrmCheckPath('PATH_TO_LOCATIONS_LIST', $this->arParams['PATH_TO_LOCATIONS_LIST'], '');
		$this->arParams['PATH_TO_LOCATIONS_ADD'] = CrmCheckPath('PATH_TO_LOCATIONS_ADD', $this->arParams['PATH_TO_LOCATIONS_ADD'], '?add');
		$this->arParams['PATH_TO_LOCATIONS_EDIT'] = CrmCheckPath('PATH_TO_LOCATIONS_EDIT', $this->arParams['PATH_TO_LOCATIONS_EDIT'], '?loc_id=#loc_id#&edit');
		$this->arParams['PATH_TO_LOCATIONS_IMPORT'] = CrmCheckPath('PATH_TO_LOCATIONS_IMPORT', $this->arParams['PATH_TO_LOCATIONS_IMPORT'], '?import');

		return true;
	}

	protected function checkIntegrity()
	{
		if(!$this->getLocationAdapter()->isIntegrityValid())
		{
			$this->errors['FATAL'][] = Loc::getMessage('CRM_CLL2_IMPORT_ERROR');

			return false;
		}

		return true;
	}

	/**
	 * Function makes some actions based on what is in $this->request
	 * @return void
	 */
	protected function performAction()
	{
		$this->componentData['LIST_HEADERS'] = $this->getLocationAdapter()->getListGridColumns();
		$this->componentData['FILTER_HEADERS'] = $this->getLocationAdapter()->getFilterColumns();
	}

	protected function getGridOpts()
	{
		if(isset($this->componentData['GRID_OPTS']))
			return $this->componentData['GRID_OPTS'];

		$this->dbResult['GRID_ID'] = self::GRID_ID;
		$this->dbResult['FORM_ID'] = $this->arParams['FORM_ID'] ?? '';
		$this->dbResult['TAB_ID'] = $this->arParams['TAB_ID'] ?? '';

		$this->componentData['GRID_OPTS'] = new CCrmGridOptions($this->dbResult['GRID_ID']);

		return $this->componentData['GRID_OPTS'];
	}

	private function createGrid(): LocationGrid
	{
		return new LocationGrid(
			new Settings(['ID' => self::GRID_ID]),
			$this->componentData['LIST_HEADERS'] ?? [],
			(bool)($this->dbResult['CAN_EDIT'] ?? false),
		);
	}

	private function createFilter(): Filter
	{
		return new Filter(
			self::GRID_ID,
			new LocationDataProvider(
				self::GRID_ID,
				LANGUAGE_ID,
				$this->componentData['FILTER_HEADERS'] ?? [],
				$this->getLocationAdapter()->getTypeList(),
			),
		);
	}

	private function getLocationAdapter(): LocationAdapter
	{
		return $this->locationAdapter ??= new LocationAdapter();
	}

	private function getLocationListService(): LocationListService
	{
		return $this->locationListService ??= new LocationListService($this->getLocationAdapter());
	}

	private function applyParentIdFromRequest(): void
	{
		if (!$this->arParams['PARENT_ID_FROM_REQUEST'])
		{
			return;
		}

		$options = new FilterOptions(self::GRID_ID);
		$fields = $options->getFilter();
		unset($fields['PRESET_ID'], $fields['FILTER_ID'], $fields['FILTER_APPLIED']);
		$fields['PARENT_ID_numsel'] = 'exact';
		$fields['PARENT_ID_from'] = (string)$this->arParams['PARENT_ID'];
		$fields['PARENT_ID_to'] = '';

		$currentSettings = $options->getFilterSettings($options->getCurrentFilterId()) ?? [];
		$currentSettings['fields'] ??= [];
		$rows = FilterOptions::fetchPresetFields($currentSettings);
		$rows[] = 'PARENT_ID';
		$options->setupDefaultFilter($fields, array_values(array_unique($rows)));
	}

	private function prepareGridResult(LocationGrid $grid): void
	{
		$pagination = $grid->getPagination();
		if ($pagination !== null)
		{
			$pagination->setRecordCount((int)($this->dbResult['ROWS_COUNT'] ?? 0));
		}

		$grid->setRawRows($this->dbResult['LOCS'] ?? []);

		$this->dbResult['grid'] = GridComponentParams::get($grid);
		$this->dbResult['filter'] = FilterComponentParams::get(
			$this->filter,
			[
				'GRID_ID' => self::GRID_ID,
				'ENABLE_LIVE_SEARCH' => true,
				'ENABLE_FIELDS_SEARCH' => 'Y',
			],
		);
		$this->dbResult['clientConfig'] = [
			'gridId' => self::GRID_ID,
			'filterId' => self::GRID_ID,
			'addUrl' => $this->arParams['PATH_TO_LOCATIONS_ADD'],
			'editUrlTemplate' => $this->arParams['PATH_TO_LOCATIONS_EDIT'],
			'importUrl' => $this->arParams['PATH_TO_LOCATIONS_IMPORT'],
			'batchController' => self::BATCH_CONTROLLER,
		];
	}

	protected function getFilter()
	{
		$this->dbResult['FILTER_PRESETS'] = [];
		$this->dbResult['FILTER'] = [];

		foreach($this->componentData['FILTER_HEADERS'] as $code => $fld)
		{
			$this->dbResult['FILTER'][] = [
				'id' => $code, 'name' => str_replace('&nbsp;', ' ', $fld['title']), 'default' => $fld['DEFAULT'],
			];
		}

		$this->dbResult['FILTER_VALUES'] = $this->getGridOpts()->GetFilter($this->dbResult['FILTER']);

		return $this->dbResult['FILTER_VALUES'];
	}

	protected function getOrder()
	{
		$arSort = [];

		$request = $this->request->getQueryList();

		$by = isset($request['by']) ? trim($request['by']) : 'ID';
		$sort = isset($request['order']) ? trim($request['order']) : 'asc';

		if(isset($request['by']) && isset($request['order']))
			$arSort = [$by => $sort];

		$gridSorting = $this->getGridOpts()->GetSorting(
			[
				'sort' => ['ID' => 'asc'],
				'vars' => ['by' => 'by', 'order' => 'order'],
			],
		);

		$this->dbResult['SORT'] = !empty($arSort) ? $arSort : $gridSorting['sort'];
		$this->dbResult['SORT_VARS'] = $gridSorting['vars'];

		return $arSort;
	}

	// get parameters from filter (filter) and grid (sort) (generalized)
	protected function getListParameters()
	{
		$filter = $this->getFilter();
		$parsedFilter = [];

		foreach($this->componentData['FILTER_HEADERS'] as $code => $field)
		{
			if(isset($filter[$code]))
				$parsedFilter[$code] = $filter[$code];
			if(isset($filter[$code . '_from']))
				$parsedFilter[$code]['FROM'] = $filter[$code . '_from'];
			if(isset($filter[$code . '_to']))
				$parsedFilter[$code]['TO'] = $filter[$code . '_to'];
		}

		// convert values to the general format Helper can understand
		$proxed = [
			'FILTER' => $parsedFilter,
			'ORDER' => $this->getOrder(),
		];

		return $proxed;
	}

	/**
	 * Here we get some data that cannot be cached for a long time
	 * @return void
	 */
	protected function obtainNonCachedData()
	{
	}

	/**
	 * Here we get some data that can be cached for a long time.
	 * @param mixed[] $cachedData Buffer for keeping data that will be put to a cache later
	 * @return void
	 */
	protected function obtainCachedData(&$cachedData)
	{
		$cachedData['TYPES'] = $this->getLocationAdapter()->getTypeList();
	}

	/**
	 * Here we obtain data that is dependent to data in the cache
	 * @return void
	 */
	protected function obtainCacheDependentData()
	{
		if ($this->grid === null || $this->filter === null)
		{
			return;
		}

		$this->getFilter();
		$this->getOrder();
		$pagination = $this->grid->getPagination();
		$select = array_values(array_unique([
			...$this->grid->getVisibleColumnsIds(),
			'NAME_' . mb_strtoupper(LANGUAGE_ID),
		]));
		$result = $this->getLocationListService()->read(
			$this->filter,
			$this->dbResult['SORT'] ?? [],
			$select,
			LANGUAGE_ID,
			$pagination?->getPageSize() ?? 20,
			$pagination?->getOffset() ?? 0,
		);

		$this->dbResult['LOCS'] = [];
		foreach ($result['rows'] as $arLoc)
		{
			$arLoc['PATH_TO_LOCATIONS_EDIT']
				= CComponentEngine::MakePathFromTemplate(
					$this->arParams['PATH_TO_LOCATIONS_EDIT'],
					['loc_id' => $arLoc['ID']],
				);

			$arLoc['TYPE_ID'] = $this->dbResult['TYPES'][$arLoc['TYPE_ID']] ?? '';

			$this->dbResult['LOCS'][$arLoc['ID']] = $arLoc;
		}

		$this->dbResult['ROWS_COUNT'] = $result['count'];
	}

	/**
	 * Move data read from database to a specially formatted $arResult
	 * @return void
	 */
	protected function formatResult()
	{
		$this->arResult =&$this->dbResult;
		$this->arResult['ERRORS'] =&$this->errors;

		if(is_array($this->componentData['LIST_HEADERS']))
		{
			// grid
			$this->arResult['HEADERS'] = [];
			foreach($this->componentData['LIST_HEADERS'] as $code => $fld)
			{
				$this->arResult['HEADERS'][] = [
					'id' => $code,
					'name' => $fld['title'],
					'sort' => $code,
					'default' => $fld['DEFAULT'],
					'editable' => false,
				];
			}
		}

		unset($this->componentData);
	}

	/**
	 * Function implements all the life cycle of our component
	 * @return void
	 */
	public function executeComponent()
	{
		if($this->checkRequiredModules() && $this->checkPermissions() && $this->checkParameters() && $this->checkIntegrity())
		{
			$this->performAction();
			$this->applyParentIdFromRequest();
			$this->grid = $this->createGrid();
			$this->grid->processRequest();
			$this->filter = $this->createFilter();
			$this->obtainData();
			$this->prepareGridResult($this->grid);
		}

		$this->formatResult();

		$this->includeComponentTemplate();
	}

	protected function checkHasErrors($fatalOnly = false)
	{
		return count($this->errors['FATAL']) || (!$fatalOnly && count($this->errors['NONFATAL']));
	}

	protected function getCacheDependences()
	{
		return	[
			static::getClassName(),
			LANGUAGE_ID,
		];
	}

	protected static function getStrForVariable($val)
	{
		return ':' . ($val ? strval($val) : '0') . ':';
	}

	/**
	 * Fetches all required data from database. Everyting that connected with data fetch lies here.
	 * @return void
	 */
	protected function obtainData()
	{
		$this->obtainNonCachedData();

		// obtain cached data
		if ($this->startCache(static::getCacheDependences()))
		{
			try
			{
				$cachedData = [];
				$this->obtainCachedData($cachedData);
			}
			catch (Exception $e)
			{
				$this->abortCache();
				throw $e;
			}

			$this->endCache($cachedData);
		}
		else
			$cachedData = $this->getCacheData();

		$this->dbResult = array_merge($this->dbResult, $cachedData);

		$this->obtainCacheDependentData();
	}

	//////////////////////////////
	// Parameter parse functions
	//////////////////////////////

	/**
	 * Function forces 'Y'/'N' value to boolean
	 * @param mixed $fld Field value
	 * @param string $default Default value
	 * @return string parsed value
	 */
	public static function tryParseBoolean(&$fld)
	{
		$fld = $fld == 'Y';

		return $fld;
	}

	/**
	 * Function processes parameter value by white list, if gets null, passes the first value in white list
	 * @param mixed $fld Field value
	 * @param string $default Default value
	 * @return string parsed value
	 */
	public static function tryParseWhiteList(&$fld, $list = [])
	{
		if(!in_array($fld, $list))
			$fld = current($list);

		return $fld;
	}

	/**
	 * Function reduces input value to integer type, and, if gets null, passes the default value
	 * @param mixed $fld Field value
	 * @param int $default Default value
	 * @param int $allowZero Allows zero-value of the parameter
	 * @return int Parsed value
	 */
	public static function tryParseInt(&$fld, $default = false, $allowZero = false)
	{
		$fld = intval($fld);
		if(!$allowZero && !$fld && $default !== false)
			$fld = $default;
			
		return $fld;
	}

	/**
	 * Function processes string value and, if gets null, passes the default value to it
	 * @param mixed $fld Field value
	 * @param string $default Default value
	 * @return string parsed value
	 */
	public static function tryParseString(&$fld, $default = false)
	{
		$fld = trim((string)$fld);
		if(!mb_strlen($fld) && $default !== false)
			$fld = $default;

		$fld = htmlspecialcharsbx($fld);

		return $fld;
	}

	/**
	 * Function processes string value and, if gets null, passes the default value to it
	 * @param mixed $fld Field value
	 * @param string $default Default value
	 * @return string parsed value
	 */
	public static function tryParseStringStrict(&$fld, $default = false)
	{
		$fld = trim((string)$fld);
		if(!mb_strlen($fld) && $default !== false)
			$fld = $default;

		$fld = preg_replace('#[^a-z0-9_-]#i', '', $fld);

		return $fld;
	}

	/**
	 * Function checks if it`s argument is a legal array for foreach() construction
	 * @param mixed $arr data to check
	 * @return boolean
	 */
	protected static function checkIsNonemptyArray($arr)
	{
		return is_array($arr) && !empty($arr);
	}

	////////////////////////
	// Cache functions
	////////////////////////

	/**
	 * Function checks if cacheing is enabled in component parameters
	 * @return boolean
	 */
	final protected function getCacheNeed()
	{
		return	intval($this->arParams['CACHE_TIME']) > 0
				&& $this->arParams['CACHE_TYPE'] != 'N'
				&& Config\Option::get("main", "component_cache_on", "Y") == "Y";
	}

	/**
	 * Function perform start of cache process, if needed
	 * @param mixed[]|string $cacheId An optional addition for cache key
	 * @return boolean True, if cache content needs to be generated, false if cache is valid and can be read
	 */
	final protected function startCache($cacheId = [])
	{
		if(!$this->getCacheNeed())
			return true;

		$this->currentCache = \Bitrix\Main\Data\Cache::createInstance();

		return $this->currentCache->startDataCache(intval($this->arParams['CACHE_TIME']), $this->getCacheKey($cacheId));
	}

	/**
	 * Function perform start of cache process, if needed
	 * @throws Main\SystemException
	 * @param mixed[] $data Data to be stored in the cache
	 * @return void
	 */
	final protected function endCache($data = false)
	{
		if(!$this->getCacheNeed())
			return;

		if($this->currentCache == 'null')
			throw new Main\SystemException('Cache were not started');

		$this->currentCache->endDataCache($data);
		$this->currentCache = null;
	}

	/**
	 * Function discard cache generation
	 * @throws Main\SystemException
	 * @return void
	 */
	final protected function abortCache()
	{
		if(!$this->getCacheNeed())
			return;

		if($this->currentCache == 'null')
			throw new Main\SystemException('Cache were not started');

		$this->currentCache->abortDataCache();
		$this->currentCache = null;
	}

	/**
	 * Function return data stored in cache
	 * @throws Main\SystemException
	 * @return void|mixed[] Data from cache
	 */
	final protected function getCacheData()
	{
		if(!$this->getCacheNeed())
			return;

		if($this->currentCache == 'null')
			throw new Main\SystemException('Cache were not started');

		return $this->currentCache->getVars();
	}

	/**
	 * Function leaves the ability to modify cache key in future.
	 * @return string Cache key to be used in CPHPCache()
	 */
	final protected function getCacheKey($cacheId = [])
	{
		if(!is_array($cacheId))
			$cacheId = [(string)$cacheId];

		$cacheId['SITE_ID'] = SITE_ID;
		$cacheId['LANGUAGE_ID'] = LANGUAGE_ID;
		// if there are two or more caches with the same id, but with different cache_time, make them separate
		$cacheId['CACHE_TIME'] = intval($this->arResult['CACHE_TIME']);

		if(defined("SITE_TEMPLATE_ID"))
			$cacheId['SITE_TEMPLATE_ID'] = SITE_TEMPLATE_ID;

		return implode('|', $cacheId);
	}

	protected static function getClassName()
	{
		return __CLASS__;
	}
}
