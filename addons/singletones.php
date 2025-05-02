<?php

/** @noinspection GlobalVariableUsageInspection */
global $DB, $APPLICATION, $USER, $USER_FIELD_MANAGER, $CACHE_MANAGER, $stackCacheManager, $obUsersCache, $INTRANET_TOOLBAR;

/** @var CDatabase $DB */
/** @xglobal $DB CDatabase */
/** @var CDatabase $GLOBALS['DB'] */
/** @var CDatabase $GLOBALS["DB"] */
$DB = $GLOBALS['DB'] = $GLOBALS['DB'] = new CDatabase();

/** @var CMain $APPLICATION */
/** @xglobal $APPLICATION CMain */
/** @var CMain $GLOBALS['APPLICATION'] */
/** @var CMain $GLOBALS["APPLICATION"] */
$APPLICATION = $GLOBALS['APPLICATION'] = $GLOBALS['APPLICATION'] = new CMain();

/** @var CUser $USER */
/** @xglobal $USER CUser */
/** @var CUser $GLOBALS['USER'] */
/** @var CUser $GLOBALS["USER"] */
$USER = $GLOBALS['USER'] = $GLOBALS['USER'] = new CUser();

/** @var CUserTypeManager $USER_FIELD_MANAGER */
/** @xglobal $USER_FIELD_MANAGER CUserTypeManager */
/** @var CUserTypeManager $GLOBALS['USER_FIELD_MANAGER'] */
/** @var CUserTypeManager $GLOBALS["USER_FIELD_MANAGER"] */
$USER_FIELD_MANAGER = $GLOBALS['USER_FIELD_MANAGER'] = $GLOBALS['USER_FIELD_MANAGER'] = new CUserTypeManager();

/** @var CCacheManager $CACHE_MANAGER */
/** @xglobal $CACHE_MANAGER CCacheManager */
/** @var CCacheManager $GLOBALS['CACHE_MANAGER'] */
/** @var CCacheManager $GLOBALS["CACHE_MANAGER"] */
$CACHE_MANAGER = $GLOBALS['CACHE_MANAGER'] = $GLOBALS['CACHE_MANAGER'] = new CCacheManager();

/** @var CStackCacheManager $stackCacheManager */
$stackCacheManager = $GLOBALS['stackCacheManager'] = new CStackCacheManager();

/**
 * extranet module
 * @xglobal CUsersInMyGroupsCache $obUsersCache
 * @global CUsersInMyGroupsCache $obUsersCache
 * @var CUsersInMyGroupsCache $obUsersCache
 */
$obUsersCache = new CUsersInMyGroupsCache();

/**
 * intranet module
 * @xglobal CIntranetToolbar $INTRANET_TOOLBAR
 * @global CIntranetToolbar $INTRANET_TOOLBAR
 * @var CIntranetToolbar $INTRANET_TOOLBAR
 */
$INTRANET_TOOLBAR = new CIntranetToolbar();

// @var $GLOBALS array{'DB' : \CDatabase, 'APPLICATION' : \CMain, 'USER' : \CUser, 'USER_FIELD_MANAGER' : \CUserTypeManager, 'CACHE_MANAGER' : \CCacheManager, 'stackCacheManager' : \CStackCacheManager}

/*
 * @global $APPLICATION \CMain
 * @global $USER \CUser
 * @global $DB \CDatabase
 * @global $USER_FIELD_MANAGER \CUserTypeManager
 * @global $CACHE_MANAGER \CCacheManager
 */

/*
 * @xglobal $APPLICATION \CMain
 * @xglobal $USER \CUser
 * @xglobal $DB \CDatabase
 * @xglobal $USER_FIELD_MANAGER \CUserTypeManager
 */
