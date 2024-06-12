<? /** @noinspection GlobalVariableUsageInspection */
global $DB,$APPLICATION,$USER,$USER_FIELD_MANAGER,$CACHE_MANAGER,$stackCacheManager;

/* @var $DB CDatabase */
/** @xglobal $DB CDatabase */
/* @var $GLOBALS['DB'] CDatabase */
/* @var $GLOBALS["DB"] CDatabase */
$DB = $GLOBALS['DB'] = $GLOBALS["DB"] = new CDatabase();

/* @var $APPLICATION CMain */
/** @xglobal $APPLICATION CMain */
/* @var $GLOBALS['APPLICATION'] CMain */
/* @var $GLOBALS["APPLICATION"] CMain */
$APPLICATION = $GLOBALS['APPLICATION'] = $GLOBALS["APPLICATION"] = new CMain();

/* @var $USER CUser */
/** @xglobal $USER CUser */
/* @var $GLOBALS['USER'] CUser */
/* @var $GLOBALS["USER"] CUser */
$USER = $GLOBALS['USER'] = $GLOBALS["USER"] = new CUser();

/* @var $USER_FIELD_MANAGER CUserTypeManager */
/** @xglobal $USER_FIELD_MANAGER CUserTypeManager */
/* @var $GLOBALS['USER_FIELD_MANAGER'] CUserTypeManager */
/* @var $GLOBALS["USER_FIELD_MANAGER"] CUserTypeManager */
$USER_FIELD_MANAGER = $GLOBALS['USER_FIELD_MANAGER'] = $GLOBALS["USER_FIELD_MANAGER"] = new CUserTypeManager();

/* @var $CACHE_MANAGER CCacheManager */
/** @xglobal $CACHE_MANAGER CCacheManager */
/* @var $GLOBALS['CACHE_MANAGER'] CCacheManager */
/* @var $GLOBALS["CACHE_MANAGER"] CCacheManager */
$CACHE_MANAGER = $GLOBALS['CACHE_MANAGER'] = $GLOBALS["CACHE_MANAGER"] = new CCacheManager();

/** @var $stackCacheManager CStackCacheManager */
$stackCacheManager = $GLOBALS["stackCacheManager"] = new CStackCacheManager();

/** @var $GLOBALS array{'DB' : \CDatabase, 'APPLICATION' : \CMain, 'USER' : \CUser, 'USER_FIELD_MANAGER' : \CUserTypeManager, 'CACHE_MANAGER' : \CCacheManager, 'stackCacheManager' : \CStackCacheManager} */

/**
 * @global $APPLICATION \CMain
 * @global $USER \CUser
 * @global $DB \CDatabase
 * @global $USER_FIELD_MANAGER \CUserTypeManager
 * @global $CACHE_MANAGER \CCacheManager
 */

/**
 * @xglobal $APPLICATION \CMain
 * @xglobal $USER \CUser
 * @xglobal $DB \CDatabase
 * @xglobal $USER_FIELD_MANAGER \CUserTypeManager
 */