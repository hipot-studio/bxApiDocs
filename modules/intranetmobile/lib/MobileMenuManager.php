<?php

namespace Bitrix\IntranetMobile;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Menu\Manager\MobileMenuManager as BaseMobileMenuManager;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Mobile\Menu\MenuList;
use Bitrix\Mobile\Menu\Analytics;

class MobileMenuManager extends BaseMobileMenuManager
{
	public static function onMobileMenuStructureBuilt(array $menu, $context): array
	{
		if (!($context instanceof Context))
		{
			return $menu;
		}

		if (!$context->isCollaber && ToolsManager::getInstance()->checkAvailabilityByToolId('workgroups'))
		{
			$groupsItem = self::prepareGroupsMenu($context);
			$menu = self::addMenuItems($menu, MenuList::SECTION_TEAMWORK, $groupsItem);
		}

		return $menu;
	}

	private static function prepareGroupsMenu(Context $context): array
	{
		$items = [];

		$workgroupsComponentVersion = \Bitrix\MobileApp\Janative\Manager::getComponentVersion("workgroups");
		$features = implode(',', \Bitrix\Mobile\Project\Helper::getMobileFeatures());
		$mandatoryFeatures = implode(',', \Bitrix\Mobile\Project\Helper::getMobileMandatoryFeatures());

		$siteDir = SITE_DIR;
		$workgroupUrlTemplate = \Bitrix\Mobile\Project\Helper::getProjectNewsPathTemplate(['siteDir' => $siteDir]);

		$siteId = SITE_ID;
		$workgroupCalendarWebPathTemplate = \Bitrix\Mobile\Project\Helper::getProjectCalendarWebPathTemplate([
			'siteDir' => $siteDir,
			'siteId' => $siteId,
		]);

		$title = Loc::getMessage('MENU_BITRIX24_SECTION_GROUPS');

		$items[] = [
			'id' => 'groups',
			'sort' => 140,
			'title' => $title,
			'imageName' => 'three_persons',
			'counter' => 'groups',
			'params' => [
				'onclick' => <<<JS
					ComponentHelper.openList({
						name: 'workgroups',
						object: 'list',
						version: "{$workgroupsComponentVersion}",
						componentParams: {
							siteId: "{$siteId}",
							siteDir: "{$siteDir}",
							pathTemplate: "{$workgroupUrlTemplate}",
							calendarWebPathTemplate: "{$workgroupCalendarWebPathTemplate}",
							features: "{$features}",
							mandatoryFeatures: "{$mandatoryFeatures}",
							currentUserId: "{$context->userId}"
						},
						widgetParams: {
							titleParams: { text: "{$title}", type: "section" },
							useSearch: false,
							doNotHideSearchResult: true
						}
					});
				JS,
				'analytics' => Analytics::groups(),
			],
		];

		if (
			Loader::includeModule('extranet')
			&& Loader::includeModule('socialnetwork')
		)
		{
			$extranetSiteId = \Bitrix\Main\Config\Option::get('extranet', 'extranet_site');
			if (
				($extranetSiteId || $context->extranet)
				&& self::hasActiveExtranetGroups($extranetSiteId, $context->userId)
			)
			{
				$workgroupCalendarWebPathTemplate = \Bitrix\Mobile\Project\Helper::getProjectCalendarWebPathTemplate([
					'siteDir' => $siteDir,
					'siteId' => $extranetSiteId,
				]);

				$title = Loc::getMessage('MENU_BITRIX24_SECTION_EXTRANET');

				$items[] = [
					'id' => 'extranet',
					'sort' => 800,
					'title' => $title,
					'imageName' => 'globe_extranet',
					'counter' => 'extranet',
					'params' => [
						'onclick' => <<<JS
						ComponentHelper.openList({
							name: 'workgroups',
							object: 'list',
							version: "{$workgroupsComponentVersion}",
							componentParams: {
								siteId: "{$extranetSiteId}",
								siteDir: "{$siteDir}",
								pathTemplate: "{$workgroupUrlTemplate}",
								calendarWebPathTemplate: "{$workgroupCalendarWebPathTemplate}",
								features: "{$features}",
								mandatoryFeatures: "{$mandatoryFeatures}",
								currentUserId: "{$context->userId}"
							},
							widgetParams: {
								titleParams: { text: "{$title}", type: "section" },
								useSearch: false,
								doNotHideSearchResult: true
							}
						});
					JS,
						'analytics' => Analytics::extranet(),
					],
				];
			}
		}

		return $items;
	}

	private static function hasActiveExtranetGroups(string $extranetSiteId, int $userId): bool
	{
		$cache = \Bitrix\Main\Data\Cache::createInstance();
		$cacheKey = "extranet_groups_{$extranetSiteId}_{$userId}";
		$cacheDir = "/mobile/extranet_groups";
		$cacheTtl = 604800; // week

		if ($cache->initCache($cacheTtl, $cacheKey, $cacheDir))
		{
			return $cache->getVars()['hasGroups'];
		}

		try
		{
			$filter = [
				'ACTIVE' => 'Y',
				'!CLOSED' => 'Y',
				'!=TYPE' => 'collab',
				'CHECK_PERMISSIONS' => $userId,
				'SITE_ID' => $extranetSiteId,
			];

			$result = \CSocNetGroup::getList([], $filter, false, ['nTopCount' => 1], ['ID']);
			$groups = $result->fetch();

			$hasGroups = (bool)$groups;

			if ($cache->startDataCache())
			{
				$taggedCache = \Bitrix\Main\Application::getInstance()->getTaggedCache();
				$taggedCache->startTagCache($cacheDir . '/' . $cacheKey);
				$taggedCache->registerTag('sonet_group');
				$taggedCache->registerTag('sonet_user2group_U' . $userId);
				$taggedCache->endTagCache();

				$cache->endDataCache(['hasGroups' => $hasGroups]);
			}

			return $hasGroups;
		}
		catch (\Exception $e)
		{
			return false;
		}
	}
}
