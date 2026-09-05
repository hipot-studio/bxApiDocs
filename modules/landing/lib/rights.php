<?php
namespace Bitrix\Landing;

use \Bitrix\Landing\Internals\RightsTable;
use \Bitrix\Main\Config\Option;
use \Bitrix\Main\Localization\Loc;
use \Bitrix\Main\UserAccessTable;
use Bitrix\Crm\Service\Container;

Loc::loadMessages(__FILE__);

class Rights
{
	/**
	 * Site entity type.
	 */
	const ENTITY_TYPE_SITE = 'S';

	/**
	 * Access types for different levels.
	 */
	const ACCESS_TYPES = [
		'denied' => 'denied',
		'read' => 'read',
		'edit' => 'edit',
		'sett' => 'sett',
		'public' => 'public',
		'delete' => 'delete'
	];

	/**
	 * Additional rights for some functionality.
	 */
	const ADDITIONAL_RIGHTS = [
		'menu24' => 'menu24',//show in main menu of Bitrix24
		'admin' => 'admin',//admin rights
		'create' => 'create',//can create new sites
		'unexportable' => 'unexportable',
		'knowledge_menu24' => 'knowledge_menu24',// show Knowledge in main menu of Bitrix24
		'knowledge_admin' => 'knowledge_admin',//admin rights
		'knowledge_create' => 'knowledge_create',//can create new Knowledge base
		'knowledge_unexportable' => 'knowledge_unexportable',
		'knowledge_extension' => 'knowledge_extension',
		'group_create' => 'group_create',//can create new social network group base
		'group_admin' => 'group_admin',//admin rights
		'group_menu24' => 'group_menu24',// show group in main menu of Bitrix24
		'group_unexportable' => 'group_unexportable',
		'vibe_create' => 'vibe_create',//can create new main page
		'vibe_admin' => 'vibe_admin',//admin rights
		'vibe_menu24' => 'vibe_menu24',// show main page in main menu of Bitrix24
	];

	const SET_PREFIX = [
		'knowledge',
		'group',
	];

	/**
	 * Scope prefixes whose unused rights are stored as a missing option, not as an empty one. The
	 * list names sections, so it holds for every code of them, the reverse right included: what a
	 * missing option of a reverse right means is decided by a branch of its own in
	 * hasAdditionalRight(), which keeps the answer such a right had before the sections were told
	 * apart. The two states answer a reverse right the opposite way: an empty option denies it
	 * (group_unexportable off, the export is allowed), a missing one grants it in the soft mode (the
	 * export is forbidden), so dropping the right from the last role of the section flips the answer
	 * instead of clearing it.
	 */
	private const DELETE_WHEN_EMPTY_PREFIXES = [
		'group_',
		'vibe_',
	];

	/**
	 * Prefix of the durable marker option of a configured right. Both signals of
	 * isSectionConfigured() are read from the roles, and a grant of the extended model touches no
	 * role: it lives in the summary option alone, so deleting that summary with the last access code
	 * erases its only trace. The marker is kept per code, not per section: the signals of the section
	 * answer for its every right at once, and a right never configured must keep the soft answer it
	 * had (an unconfigured group_menu24 stays visible while group_create was set up and cleared).
	 */
	private const RIGHT_CONFIGURED_OPTION_PREFIX = 'rights_configured_';

	/**
	 * Rights a missing option denies even in the soft mode.
	 * The main page is managed by explicitly granted rights, and its own entrances check them
	 * strictly, so an unconfigured right must not open creating and administering to everyone.
	 * The navigational vibe_menu24 is not listed: an unconfigured menu item stays visible to all.
	 */
	private const DENIED_WHEN_UNCONFIGURED = [
		'vibe_create',
		'vibe_admin',
	];

	const REVERSE_RIGHTS = [
		'unexportable',
		'knowledge_unexportable',
		'group_unexportable',
	];

	/**
	 * Allowed site ids with full access.
	 * @var int[]
	 */
	protected static $allowedSites = [];

	/**
	 * If true, rights is not checking.
	 * @var bool
	 */
	protected static $available = true;

	/**
	 * If true, rights is not checking (global mode).
	 * @var bool
	 */
	protected static $globalAvailable = true;

	/**
	 * Context user id.
	 * @var int
	 */
	protected static $userId = null;

	/**
	 * Unserialized additional rights options, by right code.
	 * @var array
	 */
	private static array $additionalOptionsCache = [];

	/**
	 * Raw additional rights options, by right code.
	 * @var array
	 */
	private static array $additionalOptionsRawCache = [];

	/**
	 * Resolved access codes checks, by context user id and sorted access codes string.
	 * @var array
	 */
	private static array $additionalAccessCodesCache = [];

	/**
	 * Whether the rights of a section were ever configured, by the scope the answer was counted for.
	 * The section is the one of the right code, not the one the process entered, and the scope is
	 * switched per command inside a single process (REST / AJAX batch), so the answers may not be
	 * shared between the sections.
	 * @var array
	 */
	private static array $sectionConfiguredCache = [];

	/**
	 * Whether the section has rights records, by the scope the answer was counted for. The scope is
	 * switched per command inside a single process (REST / AJAX batch), so the answers may not be
	 * shared between them.
	 * @var array
	 */
	private static array $existCache = [];

	/**
	 * Operations of a site, by the scope they were built under and the site id.
	 * @var array
	 */
	private static array $siteOperationsCache = [];

	/**
	 * Operations of a landing, by the scope they were built under and the landing id.
	 * @var array
	 */
	private static array $landingOperationsCache = [];

	/**
	 * Set rights checking to 'no'.
	 * @return void
	 */
	public static function setOff()
	{
		self::$available = false;
	}

	/**
	 * Set rights checking to 'yes'.
	 * @return void
	 */
	public static function setOn()
	{
		self::$available = true;
	}

	/**
	 * Set rights checking to 'no' (global mode).
	 * @return void
	 */
	public static function setGlobalOff()
	{
		self::$globalAvailable = false;
	}

	/**
	 * Set rights checking to 'yes' (global mode).
	 * @return void
	 */
	public static function setGlobalOn()
	{
		self::$globalAvailable = true;
	}

	/**
	 * Check current status for checking rights (global mode only), without the local flag
	 * and LANDING_DISABLE_RIGHTS.
	 * @return bool
	 */
	public static function isGlobalOn(): bool
	{
		return self::$globalAvailable;
	}

	/**
	 * Check current status for checking rights.
	 * @return bool
	 */
	public static function isOn()
	{
		if (
			defined('LANDING_DISABLE_RIGHTS') &&
			LANDING_DISABLE_RIGHTS === true
		)
		{
			return false;
		}
		if (!self::$globalAvailable)
		{
			return false;
		}
		return self::$available;
	}

	/**
	 * Current user is admin or not.
	 * @return bool
	 */
	public static function isAdmin()
	{
		if (self::hasAdditionalRight(self::ADDITIONAL_RIGHTS['admin'], null, false, true))
		{
			return true;
		}
		return Manager::isAdmin();
	}

	/**
	 * Returns allowed sites with full access.
	 * @return int[]
	 */
	public static function getAllowedSites(): array
	{
		return self::$allowedSites;
	}

	/**
	 * Sets context user id.
	 * @param int $uid
	 * @return void
	 */
	public static function setContextUserId(int $uid): void
	{
		self::$userId = $uid;
	}

	/**
	 * Clears context user id.
	 * @return void
	 */
	public static function clearContextUserId(): void
	{
		self::$userId = null;
	}

	/**
	 * Returns context user id (current by default).
	 * @return int
	 */
	public static function getContextUserId(): int
	{
		if (!self::$userId)
		{
			self::$userId = Manager::getUserId();
		}
		return self::$userId;
	}

	/**
	 * Available or not permission feature by current plan.
	 * @return bool
	 */
	protected static function isFeatureOn()
	{
		return Manager::checkFeature(
			Manager::FEATURE_PERMISSIONS_AVAILABLE
		);
	}

	/**
	 * Gets tasks for access.
	 * @return array
	 */
	public static function getAccessTasks()
	{
		static $tasks = [];

		if (empty($tasks))
		{
			$res = \CTask::getList(
				['LETTER' => 'ASC'],
				['MODULE_ID' => 'landing']
			);
			while ($row = $res->fetch())
			{
				$row['NAME'] = mb_substr($row['NAME'], 14);
				$tasks[$row['ID']] = $row;
			}
		}

		return $tasks;
	}

	/**
	 * Gets tasks for access.
	 * @return array
	 */
	public static function getAccessTasksReferences()
	{
		static $tasks = [];

		if (empty($tasks))
		{
			foreach (self::getAccessTasks() as $accessTask)
			{
				$tasks[$accessTask['NAME']] = $accessTask['ID'];
			}
		}

		return $tasks;
	}

	/**
	 * Remove all rows for entity.
	 * @param int|array $entityId Entity id (id or array of id).
	 * @param string $entityType Entity type.
	 * @return void
	 */
	protected static function removeData($entityId, $entityType)
	{
		if (self::isFeatureOn())
		{
			$res = RightsTable::getList([
				'select' => [
					'ID'
				],
				'filter' => [
					'ENTITY_ID' => $entityId,
					'=ENTITY_TYPE' => $entityType
				]
			]);
			while ($row = $res->fetch())
			{
				RightsTable::delete($row['ID']);
			}
		}
	}

	/**
	 * Remove all rows for site.
	 * @param int|array $siteId Site id (id or array of id).
	 * @return void
	 */
	public static function removeDataForSite($siteId)
	{
		self::removeData(
			$siteId,
			self::ENTITY_TYPE_SITE
		);
	}

	/**
	 * Get all rows for entity.
	 * @param int|array $entityId Entity id (id or array of id).
	 * @param string $entityType Entity type.
	 * @param array $preDefined Predefined array of rights.
	 * @return array
	 */
	protected static function getData($entityId, $entityType, array $preDefined = [])
	{
		static $access = null;
		$items = [];
		$codes = [];

		if ($access === null)
		{
			$access = new \CAccess;
		}

		// filter (with predefined_
		$filter = [
			'ENTITY_ID' => $entityId,
			'=ENTITY_TYPE' => $entityType
		];
		if ($preDefined)
		{
			$filter['=ACCESS_CODE'] = array_keys($preDefined);
		}

		// main query
		$res = RightsTable::getList([
			'select' => [
				'TASK_ID',
				'ACCESS_CODE'
			],
			'filter' => $filter
		]);
		while ($row = $res->fetch())
		{
			$codes[] = $row['ACCESS_CODE'];
			if (!isset($items[$row['ACCESS_CODE']]))
			{
				$row['TASK_ID'] = [$row['TASK_ID']];
				$items[$row['ACCESS_CODE']] = $row;
			}
			else
			{
				$items[$row['ACCESS_CODE']]['TASK_ID'][] = $row['TASK_ID'];
			}
			if (isset($preDefined[$row['ACCESS_CODE']]))
			{
				unset($preDefined[$row['ACCESS_CODE']]);
			}
		}

		$items = array_values($items);

		// fill with predefined
		foreach ($preDefined as $accessCode => $rightCode)
		{
			$items[] = [
				'TASK_ID' => $rightCode,
				'ACCESS_CODE' => $accessCode
			];
			$codes[] = $accessCode;
		}

		// get titles
		if ($items)
		{
			$codesNames  = $access->getNames($codes);
			foreach ($items as &$item)
			{
				if (isset($codesNames[$item['ACCESS_CODE']]))
				{
					$item['ACCESS_PROVIDER'] = (
								isset($codesNames[$item['ACCESS_CODE']]['provider']) &&
								$codesNames[$item['ACCESS_CODE']]['provider']
							)
						? $codesNames[$item['ACCESS_CODE']]['provider']
						: '';
					$item['ACCESS_NAME'] = isset($codesNames[$item['ACCESS_CODE']]['name'])
						? $codesNames[$item['ACCESS_CODE']]['name']
						: $item['ACCESS_CODE'];
				}
			}
			unset($item);
		}

		return $items;
	}

	/**
	 * Get all rows for site.
	 * @param int|array $siteId Site id (id or array of id).
	 * @param array $preDefined Predefined array of rights.
	 * @return array
	 */
	public static function getDataForSite($siteId, array $preDefined = [])
	{
		return self::getData(
			$siteId,
			self::ENTITY_TYPE_SITE,
			$preDefined
		);
	}

	/**
	 * Get all available operations for entity (for current user).
	 * @param int|array $entityId Entity id (id or array of id).
	 * @param string $entityType Entity type.
	 * @return array
	 */
	protected static function getOperations($entityId, $entityType)
	{
		// full access for allowed sites
		if (
			$entityType == self::ENTITY_TYPE_SITE &&
			in_array($entityId, self::$allowedSites)
		)
		{
			$types = self::ACCESS_TYPES;
			unset($types[self::ACCESS_TYPES['delete']]);
			return array_values($types);
		}

		// check scoped method
		if (
			$entityType == self::ENTITY_TYPE_SITE
			&& !is_array($entityId) && $entityId > 0
		)
		{
			$scopeOperationsSite = Site\Type::getOperationsForSite($entityId);
			if ($scopeOperationsSite !== null)
			{
				return array_values($scopeOperationsSite);
			}
		}

		$operations = [];
		$operationsDefault = [];
		$wasChecked = false;
		$uid = self::getContextUserId();
		$extendedMode = self::isExtendedMode();

		// full access for admin
		if (
			$uid &&
			self::isOn() &&
			!self::isAdmin() &&
			self::isFeatureOn() &&
			self::exist()
		)
		{
			$wasChecked = true;
			$entityIdFilter = $entityId;
			if (is_array($entityIdFilter))
			{
				$entityIdFilter[] = 0;
			}
			else
			{
				$entityIdFilter = [
					$entityIdFilter, 0
				];
			}
			$filter = [
				'ENTITY_ID' => $entityIdFilter,
				'=ENTITY_TYPE' => $entityType,
				'USER_ACCESS.USER_ID' => $uid,
				'!TASK_OPERATION.OPERATION.NAME' => false
			];
			if ($extendedMode)
			{
				$filter['ROLE_ID'] = 0;
			}
			else
			{
				$filter['ROLE_ID'] = Role::getExpectedRoleIds();
			}
			$res = RightsTable::getList(
				[
					'select' => [
						'ENTITY_ID',
						'OPERATION_NAME' => 'TASK_OPERATION.OPERATION.NAME'
					],
					'filter' => $filter
				]
			);
			while ($row = $res->fetch())
			{
				if ($row['ENTITY_ID'] == 0)
				{
					$operationsDefault[] = mb_substr($row['OPERATION_NAME'], 8);
					continue;
				}
				if (!isset($operations[$row['ENTITY_ID']]))
				{
					$operations[$row['ENTITY_ID']] = array();
				}
				$operations[$row['ENTITY_ID']][] = mb_substr($row['OPERATION_NAME'], 8);
				$operations[$row['ENTITY_ID']] = array_unique($operations[$row['ENTITY_ID']]);
			}
		}

		// set full rights, if rights are empty
		foreach ((array) $entityId as $id)
		{
			if (!isset($operations[$id]))
			{
				if ($wasChecked && !$extendedMode)
				{
					$operations[$id] = !empty($operationsDefault)
						? $operationsDefault
						: [self::ACCESS_TYPES['denied']];
				}
				else
				{
					$operations[$id] = array_values(self::ACCESS_TYPES);
				}
			}
		}

		return is_array($entityId)
				? $operations
				: $operations[$entityId];
	}

	/**
	 * Returns  all available operations for site (for current user).
	 * @param int|array $siteId Site id (id or array of id).
	 * @return array
	 */
	public static function getOperationsForSite($siteId): array
	{
		if (
			is_array($siteId) ||
			$siteId == 0 ||
			Site::ping($siteId, true)
		)
		{
			return self::getOperations(
				$siteId,
				self::ENTITY_TYPE_SITE
			);
		}
		else
		{
			return [];
		}
	}

	/**
	 * Can current user do something.
	 * @param int $siteId Site id.
	 * @param string $accessType Access type code.
	 * @param bool $deleted And from recycle bin.
	 * @return boolean
	 */
	public static function hasAccessForSite($siteId, $accessType, $deleted = false)
	{
		$siteId = intval($siteId);

		if (!is_string($accessType))
		{
			return false;
		}

		// the matrix depends on the scope - it is built from the roles of the current one, and a
		// scope may define operations of its own - so the decisions are kept per scope: the scope
		// is switched per command inside a single process (REST / AJAX batch)
		$scopeKey = (string)Site\Type::getCurrentScopeId();

		if (!isset(self::$siteOperationsCache[$scopeKey][$siteId]))
		{
			if ($siteId === 0 || !self::isOn() || Site::ping($siteId, $deleted))
			{
				self::$siteOperationsCache[$scopeKey][$siteId] = self::getOperations(
					$siteId,
					self::ENTITY_TYPE_SITE
				);
			}
			else
			{
				self::$siteOperationsCache[$scopeKey][$siteId] = [];
			}
		}

		return in_array($accessType, self::$siteOperationsCache[$scopeKey][$siteId]);
	}

	/**
	 * Can current user do something.
	 * @param int $landingId Landing id.
	 * @param string $accessType Access type code.
	 * @return boolean
	 */
	public static function hasAccessForLanding($landingId, $accessType)
	{
		$landingId = intval($landingId);

		if (!is_string($accessType))
		{
			return false;
		}

		// same as in hasAccessForSite(): the matrix belongs to the scope it was built under
		$scopeKey = (string)Site\Type::getCurrentScopeId();

		if (!isset(self::$landingOperationsCache[$scopeKey][$landingId]))
		{
			$site = Landing::getList([
 				'select' => [
					'SITE_ID'
				],
				'filter' => [
					'ID' => $landingId,
					'=SITE.DELETED' => ['Y', 'N'],
					'=DELETED' => ['Y', 'N']
				]
			])->fetch();

			if ($site)
			{
				self::$landingOperationsCache[$scopeKey][$landingId] = self::getOperations(
					$site['SITE_ID'],
					self::ENTITY_TYPE_SITE
				);
			}
			else
			{
				self::$landingOperationsCache[$scopeKey][$landingId] = [];
			}
		}

		return in_array($accessType, self::$landingOperationsCache[$scopeKey][$landingId]);
	}

	/**
	 * Set operations for entity.
	 * @param int $entityId Entity id.
	 * @param string $entityType Entity type.
	 * @param array $rights Rights array (set empty for clear rights).
	 * @return boolean
	 */
	protected static function setOperations($entityId, $entityType, array $rights = [])
	{
		if (!self::isFeatureOn())
		{
			return false;
		}

		$tasks = self::getAccessTasksReferences();
		$entityId = intval($entityId);

		self::removeData(
			$entityId,
			$entityType
		);

		// add new rights
		foreach ($rights as $accessCode => $rightCodes)
		{
			$rightCodes = (array) $rightCodes;
			if (in_array(self::ACCESS_TYPES['denied'], $rightCodes))
			{
				$rightCodes = [self::ACCESS_TYPES['denied']];
			}
			else if (!in_array(self::ACCESS_TYPES['read'], $rightCodes))
			{
				$rightCodes[] = self::ACCESS_TYPES['read'];
			}

			foreach ($rightCodes as $rightCode)
			{
				if (isset($tasks[$rightCode]))
				{
					RightsTable::add([
						'ENTITY_ID' => $entityId,
						'ENTITY_TYPE' => $entityType,
						'TASK_ID' => $tasks[$rightCode],
						'ACCESS_CODE' => $accessCode
					]);
				}
			}
		}

		return true;
	}

	/**
	 * Set operations for site.
	 * @param int $siteId Site id.
	 * @param array $rights Rights array (set empty for clear rights).
	 * @return bool
	 */
	public static function setOperationsForSite($siteId, array $rights = [])
	{
		$siteId = intval($siteId);

		if ($siteId == 0 || Site::ping($siteId))
		{
			return self::setOperations(
				$siteId,
				self::ENTITY_TYPE_SITE,
				$rights
			);
		}
		else
		{
			return false;
		}
	}

	/**
	 * If any records of rights exists.
	 * @return bool
	 */
	protected static function exist()
	{
		$type = Site\Type::getCurrentScopeId();
		// the answer counts the records of one scope only, so it is kept per scope: a single answer
		// for the whole process would let a command of a scope without rights of its own turn the
		// rights filter off for every command after it
		$cacheKey = (string)$type;

		if (!isset(self::$existCache[$cacheKey]))
		{
			$res = RightsTable::getList([
				'select' => [
					'ID'
				],
				'filter' => $type
						? ['=ROLE.TYPE' => $type]
						: [],
				'limit' => 1
			]);
			self::$existCache[$cacheKey] = (bool) $res->fetch();
		}

		return self::$existCache[$cacheKey];
	}

	/**
	 * Gets access filter for current user.
	 * @param array $additionalFilterOr Additional filter for OR section.
	 * @return array
	 */
	public static function getAccessFilter(array $additionalFilterOr = [])
	{
		$filter = [];

		if (
			self::isOn() &&
			!self::isAdmin() &&
			self::isFeatureOn() &&
			self::exist()
		)
		{
			$tasks = self::getAccessTasksReferences();
			$extendedRights = self::isExtendedMode();
			$uid = self::getContextUserId();

			if ($extendedRights)
			{
				$filter[] = [
					'LOGIC' => 'OR',
					[
						'!RIGHTS.TASK_ID' => $tasks[Rights::ACCESS_TYPES['denied']],
						'RIGHTS.USER_ACCESS.USER_ID' => $uid
					],
					[
						'=RIGHTS.TASK_ID' => null
					],
					$additionalFilterOr
				];
			}
			else
			{
				if ($additionalFilterOr)
				{
					$filter[] = [
						'LOGIC' => 'OR',
						[
							'!RIGHTS.TASK_ID' => $tasks[Rights::ACCESS_TYPES['denied']],
							'RIGHTS.USER_ACCESS.USER_ID' => $uid
						],
						$additionalFilterOr
					];
				}
				else
				{
					$filter['RIGHTS.USER_ACCESS.USER_ID'] = $uid;
					$filter['!RIGHTS.TASK_ID'] = $tasks[Rights::ACCESS_TYPES['denied']];
				}
			}
		}

		return $filter;
	}

	/**
	 * Extended mode available.
	 * @return bool
	 */
	public static function isExtendedMode()
	{
		if (Manager::isB24())
		{
			return Manager::getOption('rights_extended_mode', 'N') == 'Y';
		}
		else
		{
			return true;
		}
	}

	/**
	 * May the current user switch the rights mode?
	 * The mode option is portal wide, so an admin of a scoped section must not switch it.
	 * @return bool
	 */
	public static function canSwitchMode(): bool
	{
		return Site\Type::getCurrentScopeId() === null || Manager::isAdmin();
	}

	/**
	 * Switch extended mode.
	 * @return void
	 */
	public static function switchMode()
	{
		if (!self::isFeatureOn())
		{
			return;
		}
		if (!self::canSwitchMode())
		{
			return;
		}
		$current = Manager::getOption('rights_extended_mode', 'N');
		$current = ($current == 'Y') ? 'N' : 'Y';
		Manager::setOption('rights_extended_mode', $current);
	}

	/**
	 * Refresh additional rights for all roles.
	 * @param array $additionalRights Array for set additional.
	 * @return void
	 */
	public static function refreshAdditionalRights(array $additionalRights = [])
	{
		if (!self::isFeatureOn())
		{
			return;
		}

		$rights = [];
		foreach (self::ADDITIONAL_RIGHTS as $right)
		{
			$rights[$right] = [];
		}

		// get additional from all roles
		$res = Role::getList([
			'select' => [
				'ID', 'ACCESS_CODES', 'ADDITIONAL_RIGHTS'
			]
		]);
		while ($row = $res->fetch())
		{
			$row['ACCESS_CODES'] = (array) $row['ACCESS_CODES'];
			$row['ADDITIONAL_RIGHTS'] = (array) $row['ADDITIONAL_RIGHTS'];
			foreach ($row['ADDITIONAL_RIGHTS'] as $right)
			{
				if (isset($rights[$right]))
				{
					$rights[$right][$row['ID']] = $row['ACCESS_CODES'];
				}
			}
		}

		// refresh options
		foreach ($rights as $code => $right)
		{
			// gets current from option
			$option = Manager::getOption('access_codes_' . $code, '');
			$option = unserialize($option, ['allowed_classes' => false]);
			if (isset($option[0]))
			{
				$right[0] = $option[0];
			}

			// rewrite some rights, if need
			if (
				isset($additionalRights[$code]) &&
				is_array($additionalRights[$code])
			)
			{
				foreach ($additionalRights[$code] as $i => $accCodes)
				{
					$right[$i] = (array) $accCodes;
				}
			}

			// an extended grant without access codes is not a value, it must not outweigh the delete below
			if (isset($right[0]) && empty($right[0]))
			{
				unset($right[0]);
			}

			// the delete below erases the last trace of an extended grant - the roles carry none -
			// so the fact of the configuration is written down while the value is still visible
			if (
				self::isDeletedWhenEmpty($code)
				&& ($right || (is_array($option) && array_filter($option)))
			)
			{
				self::markRightConfigured($code);
			}

			// set new rights in option
			if (empty($right) && self::isDeletedWhenEmpty($code))
			{
				Option::delete('landing', ['name' => 'access_codes_' . $code]);
			}
			else
			{
				Manager::setOption('access_codes_' . $code, $right ? serialize($right) : '');
			}

			// clear menu cache
			if (Manager::isB24())
			{
				Manager::getCacheManager()->clearByTag(
					'bitrix24_left_menu'
				);
				Manager::getCacheManager()->cleanDir(
					'menu'
				);
				\CBitrixComponent::clearComponentCache(
					'bitrix:menu'
				);
			}
		}

		self::resetAdditionalRightsCache();
	}

	/**
	 * Is the right stored as a missing option while no role grants it?
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @return bool
	 */
	private static function isDeletedWhenEmpty(string $code): bool
	{
		foreach (self::DELETE_WHEN_EMPTY_PREFIXES as $prefix)
		{
			if (str_starts_with($code, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Set additional right.
	 * The code is accepted only while it belongs to the scope currently entered, so a caller must
	 * enter the scope it means before the call or read the answer: the scope lives in a static of
	 * Site\Type, and Site\Type::setScope() leaves silently when it is given an empty value, which
	 * makes an agent, a console script or a migration keep whatever scope the process had before.
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @param array $accessCodes Additional rights array.
	 * @return bool True when the code is accepted for the refresh, false when it is rejected. The
	 * answer is about the code, not about the option: with the permissions feature off the refresh
	 * writes nothing while the code still counts as accepted.
	 */
	public static function setAdditionalRightExtended($code, array $accessCodes = []): bool
	{
		if (!is_string($code))
		{
			return false;
		}
		// the rejection must happen before the refresh: an empty grant of a foreign code would
		// otherwise delete the option of another scope instead of doing nothing
		if (!self::isExtendedGrantAcceptable($code))
		{
			return false;
		}
		self::refreshAdditionalRights([
		  	$code => [
				0 => $accessCodes
			]
		]);

		return true;
	}

	/**
	 * Gets additional right.
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @return array
	 */
	public static function getAdditionalRightExtended($code)
	{
		static $access = null;
		$return = [];

		if (!is_string($code))
		{
			return $return;
		}
		if ($access === null)
		{
			$access = new \CAccess;
		}

		$option = Manager::getOption('access_codes_' . $code, '');
		$option = unserialize($option, ['allowed_classes' => false]);
		$accessCodes = isset($option[0]) ? (array)$option[0] : [];
		$codesNames  = $access->getNames($accessCodes);

		foreach ($accessCodes as $code)
		{
			if (isset($codesNames[$code]))
			{
				$provider = (
					isset($codesNames[$code]['provider']) &&
					$codesNames[$code]['provider']
				)
					? $codesNames[$code]['provider']
					: '';
				$name = isset($codesNames[$code]['name'])
					? $codesNames[$code]['name']
					: $code;
				$return[$code] = [
					'CODE' => $code,
					'PROVIDER' => $provider,
					'NAME' => $name
				];
			}
		}

		return $return;
	}

	/**
	 * Does the code from ADDITIONAL_RIGHTS belong to the set of the scope?
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @param string|null $type Scope id, null for the default scope.
	 * @return bool
	 */
	public static function isRightInScope(string $code, ?string $type): bool
	{
		return self::scopeOfCode($code) === $type;
	}

	/**
	 * Does the scope have a right of its own under the given short code? Unlike isRightInScope(), the
	 * code is the short one of the registry key ('create'), the way hasAdditionalRight() takes it: the
	 * caller learns whether the scope answers for the code itself or the base scope answers for it.
	 * @param string $code Short code, a key of ADDITIONAL_RIGHTS without the prefix of a scope.
	 * @param string|null $scope Scope id, null for the default scope.
	 * @return bool
	 */
	public static function hasCodeInScope(string $code, ?string $scope): bool
	{
		if ($scope !== null)
		{
			$code = mb_strtolower($scope) . '_' . $code;
		}

		return array_key_exists($code, self::ADDITIONAL_RIGHTS);
	}

	/**
	 * Scope the code from ADDITIONAL_RIGHTS belongs to.
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @return string|null Scope id as it is stored, null for a code of the default scope.
	 */
	private static function scopeOfCode(string $code): ?string
	{
		if (mb_strpos($code, '_') > 0)
		{
			[$prefix, ] = explode('_', $code);

			return mb_strtoupper($prefix);
		}

		return null;
	}

	/**
	 * Were the rights of the section the code belongs to ever configured? A section whose unused
	 * summaries are deleted cannot tell "the right was taken away from every role" from "the rights
	 * of the section were never touched" by the missing option alone, so the section answers it: its
	 * demo roles were installed, or a role of it carries a right of it. A grant of the extended model
	 * is answered by the marker of the code itself (isRightConfigured()), not here: it says nothing
	 * about the rest of the section.
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @return bool
	 */
	private static function isSectionConfigured(string $code): bool
	{
		$scope = self::scopeOfCode($code);
		if ($scope === null)
		{
			return false;
		}

		if (isset(self::$sectionConfiguredCache[$scope]))
		{
			return self::$sectionConfiguredCache[$scope];
		}

		// the marker is written as 'N' as well, so its value is asked for, not its existence
		if (Manager::getOption('role_demo_installed_' . mb_strtolower($scope), 'N') === 'Y')
		{
			self::$sectionConfiguredCache[$scope] = true;

			return true;
		}

		self::$sectionConfiguredCache[$scope] = self::hasRoleWithSectionRight($scope);

		return self::$sectionConfiguredCache[$scope];
	}

	/**
	 * Was the right itself ever configured? The summary of a delete-when-empty code is deleted along
	 * with its last access code, so a grant of the extended model - which lives in that summary alone
	 * and touches no role - would leave nothing behind and the soft check would allow everyone again.
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @return bool
	 */
	private static function isRightConfigured(string $code): bool
	{
		return Manager::getOption(self::RIGHT_CONFIGURED_OPTION_PREFIX . $code, 'N') === 'Y';
	}

	/**
	 * Writes the durable marker of a configured right. It is never taken back: a right once set up
	 * stays configured, the same way the marker of the demo installation does, so that taking the
	 * grant away reads as a denial to everyone instead of a section nobody has touched.
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @return void
	 */
	private static function markRightConfigured(string $code): void
	{
		if (!self::isRightConfigured($code))
		{
			Manager::setOption(self::RIGHT_CONFIGURED_OPTION_PREFIX . $code, 'Y');
		}
	}

	/**
	 * Does a role of the scope carry an additional right of it? The field is serialized, so the rows
	 * are sorted out here instead of by the query. A role holding prefixless legacy codes only is no
	 * signal: the section stays unconfigured, and the soft check keeps allowing.
	 * @param string $scope Scope id, as it is stored in the type of a role.
	 * @return bool
	 */
	private static function hasRoleWithSectionRight(string $scope): bool
	{
		$res = Role::getList([
			'select' => [
				'ADDITIONAL_RIGHTS'
			],
			'filter' => [
				'=TYPE' => $scope
			]
		]);
		while ($row = $res->fetch())
		{
			foreach ((array)$row['ADDITIONAL_RIGHTS'] as $right)
			{
				if (self::isRightInScope((string)$right, $scope))
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * May the extended grant write the code under the scope currently entered?
	 * The registry is asked along with the scope, because refreshAdditionalRights() builds its loop
	 * out of ADDITIONAL_RIGHTS and passes over everything else in silence: a code the registry does
	 * not hold would otherwise be reported as saved while nothing was written.
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @return bool
	 */
	public static function isExtendedGrantAcceptable(string $code): bool
	{
		return array_key_exists($code, self::ADDITIONAL_RIGHTS)
			&& self::isRightInScope($code, Site\Type::getCurrentScopeId());
	}

	/**
	 * Gets additional rights with labels.
	 * @return array
	 */
	public static function getAdditionalRightsLabels()
	{
		$rights = [];

		$type = Site\Type::getCurrentScopeId();

		foreach (self::ADDITIONAL_RIGHTS as $right)
		{
			if (!self::isRightInScope($right, $type))
			{
				continue;
			}
			$rights[$right] = Loc::getMessage('LANDING_RIGHTS_R_'.mb_strtoupper($right));
		}

		return $rights;
	}

	/**
	 * Has user some extra access?
	 * @return bool
	 */
	protected static function hasExtraRights(): bool
	{
		// has context user access to crm forms
		if (\Bitrix\Main\Loader::includeModule('crm'))
		{
			$crmUserPermissions = Container::getInstance()->getUserPermissions(self::getContextUserId());
			if ($crmUserPermissions->webForm()->canEdit())
			{
				// grant access to crm forms sites
				$res = Site::getList([
					'select' => [
						'ID'
					],
					'filter' => [
						'%=CODE' => '/' . Site\Type::PSEUDO_SCOPE_CODE_FORMS . '%',
						'=SPECIAL' => 'Y',
						'CHECK_PERMISSIONS' => 'N'
					]
				]);
				while ($row = $res->fetch())
				{
					self::$allowedSites[] = $row['ID'];
				}

				return true;
			}
		}
		return false;
	}

	/**
	 * Has current user additional right or not.
	 * @param string $code Code from ADDITIONAL_RIGHTS.
	 * @param string $type Scope type.
	 * @param bool $checkExtraRights Check extra rights.
	 * @return bool
	 */
	public static function hasAdditionalRight($code, $type = null, bool $checkExtraRights = false, bool $strict = false)
	{
		if ($checkExtraRights && self::hasExtraRights())
		{
			return true;
		}

		if (!is_string($code))
		{
			return false;
		}
		if ($type === null)
		{
			$type = Site\Type::getCurrentScopeId();
		}

		if ($type !== null)
		{
			$type = mb_strtolower($type);
			$code = $type . '_' . $code;
		}

		if (array_key_exists($code, self::ADDITIONAL_RIGHTS))
		{
			if (!self::isFeatureOn())
			{
				return true;
			}

			if (!self::getContextUserId())
			{
				return false;
			}

			if (Manager::isAdmin())
			{
				if (in_array($code, self::REVERSE_RIGHTS))
				{
					return false;
				}
				return true;
			}

			$accessCodes = [];

			if (!array_key_exists($code, self::$additionalOptionsCache))
			{
				self::$additionalOptionsRawCache[$code] = Manager::getOption('access_codes_' . $code);
				if (self::$additionalOptionsRawCache[$code] === null)
				{
					self::$additionalOptionsCache[$code] = null;
				}
				else if (self::$additionalOptionsRawCache[$code] === '')
				{
					self::$additionalOptionsCache[$code] = [];
				}
				else
				{
					self::$additionalOptionsCache[$code] = unserialize(
						self::$additionalOptionsRawCache[$code],
						['allowed_classes' => false],
					);
				}
			}
			$option = self::$additionalOptionsCache[$code];

			if (!is_array($option))
			{
				if (self::$additionalOptionsRawCache[$code] !== null)
				{
					return false;
				}

				if (in_array($code, self::DENIED_WHEN_UNCONFIGURED, true))
				{
					return false;
				}

				if ($strict)
				{
					return false;
				}

				// the criterion of the section would invert the meaning of a reverse right, so its
				// answer for a missing option does not move
				if (in_array($code, self::REVERSE_RIGHTS, true))
				{
					return true;
				}

				if (
					self::isDeletedWhenEmpty($code)
					&& (self::isRightConfigured($code) || self::isSectionConfigured($code))
				)
				{
					// the right or its section was configured, so the missing summary is a denial
					// to everyone
					return false;
				}

				return true;
			}

			if (empty($option))
			{
				return false;
			}

			if (self::isExtendedMode())
			{
				if (isset($option[0]) && is_array($option[0]))
				{
					$accessCodes = $option[0];
				}
			}
			else
			{
				if (isset($option[0]))
				{
					unset($option[0]);
				}
				foreach ($option as $roleAccess)
				{
					$accessCodes = array_merge($accessCodes, (array)$roleAccess);
				}
				$accessCodes = array_unique($accessCodes);
			}

			if ($accessCodes)
			{
				sort($accessCodes);
				$cacheKey = self::getContextUserId() . ':' . implode('|', $accessCodes);

				if (array_key_exists($cacheKey, self::$additionalAccessCodesCache))
				{
					return self::$additionalAccessCodesCache[$cacheKey];
				}

				$res = UserAccessTable::getList([
					'select' => [
						'USER_ID'
					],
					'filter' => [
						'=ACCESS_CODE' => $accessCodes,
						'USER_ID' => self::getContextUserId()
					]
				]);
				self::$additionalAccessCodesCache[$cacheKey] = (boolean)$res->fetch();
				return self::$additionalAccessCodesCache[$cacheKey];
			}

			return false;
		}

		return false;
	}

	/**
	 * @internal Resets hasAdditionalRight() runtime caches.
	 * @return void
	 */
	public static function resetAdditionalRightsCache(): void
	{
		self::$additionalOptionsCache = [];
		self::$additionalOptionsRawCache = [];
		self::$additionalAccessCodesCache = [];
		self::resetSectionConfiguredCache();
	}

	/**
	 * @internal Resets the isSectionConfigured() runtime cache. Called on every write of a role as
	 * well: a role is written past the recomputation of the summaries, and the criterion counts the
	 * roles of the section.
	 * @return void
	 */
	public static function resetSectionConfiguredCache(): void
	{
		self::$sectionConfiguredCache = [];
	}

	/**
	 * @internal Resets the exist() runtime cache.
	 * @return void
	 */
	public static function resetExistCache(): void
	{
		self::$existCache = [];
	}

	/**
	 * @internal Resets hasAccessForSite() and hasAccessForLanding() runtime caches.
	 * @return void
	 */
	public static function resetAccessCache(): void
	{
		self::$siteOperationsCache = [];
		self::$landingOperationsCache = [];
	}
}
