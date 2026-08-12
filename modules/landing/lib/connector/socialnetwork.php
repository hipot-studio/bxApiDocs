<?php
namespace Bitrix\Landing\Connector;

use Bitrix\Landing\Binding;
use Bitrix\Landing\Copy\Integration\Group;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Restriction;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class SocialNetwork
{
	/**
	 * Binding code short.
	 */
	const SETTINGS_CODE_SHORT = 'knowledge';

	/**
	 * Binding code.
	 */
	const SETTINGS_CODE = 'landing_knowledge';

	/**
	 * Path for binding group with new site.
	 * @todo: it's not good, specify path in the code, but temporary it's ok
	 */
	const PATH_GROUP_BINDING = 'kb/binding/group/create.php?groupId=#groupId#';

	/**
	 * Gets binding row by group id.
	 * @param int $groupId Group id.
	 * @param bool $checkAccess Check read access.
	 * @return array
	 */
	public static function getBindingRow(int $groupId, bool $checkAccess = true)
	{
		\Bitrix\Landing\Site\Type::setScope(
			\Bitrix\Landing\Site\Type::SCOPE_CODE_GROUP
		);

		$groupId = intval($groupId);
		$bindings = Binding\Group::getList($groupId);

		if ($bindings)
		{
			$bindings = array_pop($bindings);

			if ($bindings['ENTITY_TYPE'] == Binding\Entity::ENTITY_TYPE_SITE)
			{
				$hasAccess = !$checkAccess || Rights::hasAccessForSite(
					$bindings['ENTITY_ID'],
					Rights::ACCESS_TYPES['read']
				);
				if ($hasAccess)
				{
					return $bindings;
				}
			}
		}

		return [];
	}

	/**
	 * Builds and returns social group menu link.
	 * @param int $groupId Group id.
	 * @param bool $returnCreateLink If true and link is no exist, returns create link.
	 * @return string
	 */
	public static function getSocNetMenuUrl($groupId, $returnCreateLink = true)
	{
		if (Option::get(Group::MODULE_ID, Group::CHECKER_OPTION . $groupId, '') == 'Y')
		{
			return '';
		}

		// tariff limits
		if (!Restriction\Manager::isAllowed('limit_crm_free_knowledge_base_project'))
		{
			$asset = \Bitrix\Main\Page\Asset::getInstance();
			$asset->addString(
				$asset->insertJs(
					'var KnowledgeCreate = function() 
						{
							' . Restriction\Manager::getActionCode('limit_crm_free_knowledge_base_project') . '
						};',
					'',
					true
				)
			);
			return 'javascript:void(KnowledgeCreate());';
		}

		$link = '';
		$groupId = intval($groupId);
		$bindings = self::getBindingRow($groupId, false);

		// binding exist
		if ($bindings)
		{
            if (self::canPerformOperation($groupId, Rights::ACCESS_TYPES['read']))
            {
                $link = $bindings['PUBLIC_URL'];
                self::processTabHit($link);
            }
		}
		// binding don't exist, allow to create new one
		else if ($returnCreateLink && self::canCreateNewBinding($groupId))
		{
			\CJSCore::init('sidepanel');
			$link = SITE_DIR . str_replace('#groupId#', $groupId, self::PATH_GROUP_BINDING);
		}

		return $link;
	}

	/**
	 * Returns title knowledge of group.
	 * @param int $groupId Group id.
	 * @return string
	 */
	public static function getSocNetMenuTitle($groupId)
	{
		$title = '';
		$groupId = intval($groupId);
		$bindings = self::getBindingRow($groupId, false);
		if ($bindings['TITLE'])
		{
			$title = $bindings['TITLE'];
		}
		return $title;
	}

	/**
	 * Fill settings array for social network group.
	 * @param array &$socNetFeaturesSettings Settings array.
	 * @return void
	 */
	public static function onFillSocNetFeaturesList(&$socNetFeaturesSettings)
	{
		$scopeCode = \Bitrix\Landing\Site\Type::SCOPE_CODE_GROUP;
		if (
			\Bitrix\Landing\Site\Type::isEnabled($scopeCode) &&
			\Bitrix\Main\ModuleManager::isModuleInstalled('intranet')
		)
		{
			$restrictedAccess = [SONET_ENTITY_GROUP => [SONET_ROLES_ALL, SONET_ROLES_AUTHORIZED]];

			$socNetFeaturesSettings[self::SETTINGS_CODE] = [
				'allowed' => [SONET_ENTITY_GROUP],
				'title' => Loc::getMessage('LANDING_CONNECTOR_SN_TITLE'),
				'operation_titles' => [
					Rights::ACCESS_TYPES['read'] => Loc::getMessage('LANDING_CONNECTOR_SN_PERMS_READ'),
					Rights::ACCESS_TYPES['edit'] => Loc::getMessage('LANDING_CONNECTOR_SN_PERMS_EDIT'),
					Rights::ACCESS_TYPES['sett'] => Loc::getMessage('LANDING_CONNECTOR_SN_PERMS_SETT'),
					Rights::ACCESS_TYPES['delete'] => Loc::getMessage('LANDING_CONNECTOR_SN_PERMS_DELETE'),
				],
				'operations' => [
					Rights::ACCESS_TYPES['read'] => [SONET_ENTITY_GROUP => SONET_ROLES_USER],
					Rights::ACCESS_TYPES['edit'] => [SONET_ENTITY_GROUP => SONET_ROLES_USER, 'restricted' => $restrictedAccess],
					Rights::ACCESS_TYPES['sett'] => [SONET_ENTITY_GROUP => SONET_ROLES_USER, 'restricted' => $restrictedAccess],
					Rights::ACCESS_TYPES['delete'] => [SONET_ENTITY_GROUP => SONET_ROLES_USER, 'restricted' => $restrictedAccess],
				],
				'minoperation' => ['read'],
			];
		}
	}

	/**
	 * Invokes when changing §permissions of socialnetwork group is occurred.
	 *
	 * @param int $id Feature id.
	 * @param array $fields Feature fields.
	 * @return void
	 */
	public static function onSocNetFeaturesUpdate(int $id, array $fields): void
	{
		$groupId = self::getGroupIdByFeatureId($id);

		if ($groupId)
		{
			AddEventHandler('main', 'onEpilog', function() use($groupId)
			{
				$siteId = Binding\Group::getSiteIdByGroupId($groupId);
				if ($siteId)
				{
					$binding = new \Bitrix\Landing\Binding\Group($groupId);
					$binding->rebindSite($siteId);
				}
			});
		}
	}

	/**
	 * Fill menu array for social network group.
	 * @param array &$result Menu array.
	 * @return void
	 */
	public static function onFillSocNetMenu(&$result)
	{
		// allowed only for groups
		if (!isset($result['Group']['ID']))
		{
			return;
		}
		if (!isset($result['Urls']['View']))
		{
			return;
		}

		$enable = false;
		if (
			!Loader::includeModule('intranet')
			|| Restriction\ToolAvailabilityManager::getInstance()->check('knowledge_base')
		)
		{
			$enable = true;
		}

		if ($enable)
		{
			// is enabled in features or not
			if (!empty($result['ActiveFeatures']))
			{
				$enable = array_key_exists(
					self::SETTINGS_CODE,
					$result['ActiveFeatures']
				);
			}
			else
			{
				$enable = false;
			}
		}

		if ($enable)
		{
			$url = self::getSocNetMenuUrl($result['Group']['ID']);
			if (!$url)
			{
				$enable = false;
			}
			$title = self::getSocNetMenuTitle($result['Group']['ID']);
			if ($title !== '')
			{
				$title = ' - ' . $title;
			}
		}
		else
		{
			$url = '';
			$title = '';
		}

		// build menu params
		$result['CanView'][self::SETTINGS_CODE] = $enable;
		$result['Title'][self::SETTINGS_CODE] = Loc::getMessage('LANDING_CONNECTOR_SN_TITLE') . $title;
		$result['Urls'][self::SETTINGS_CODE] = $url;
	}

	/**
	 * Returns true, if current site is extranet.
	 * @return bool
	 */
	protected static function isExtranet()
	{
		if (\Bitrix\Main\Loader::includeModule('extranet'))
		{
			return \CExtranet::isExtranetSite();
		}

		return false;
	}

	/**
	 * If current hit is for opening url.
	 * @param string $url Url for opening.
	 * @return void
	 */
	protected static function processTabHit($url)
	{
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
		if ($request->get('tab') == self::SETTINGS_CODE_SHORT)
		{
			$requestedPage = $request->get('page');
			$url = self::resolveKnowledgeDeepLinkUrl($url, is_string($requestedPage) ? $requestedPage : null);
			self::insertSliderOpenScript($url);
		}
	}

	/**
	 * Opens knowledge base of the group in the slider, if current hit is a deep link to it.
	 * Repeats all conditions of the group menu item: no menu item - no opening.
	 * @param int $groupId Group id.
	 * @return void
	 */
	public static function processGroupKnowledgeDeepLink(int $groupId): void
	{
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
		if ($request->get('tab') !== self::SETTINGS_CODE_SHORT)
		{
			return;
		}
		if ($groupId <= 0)
		{
			return;
		}

		// knowledge base of the group is still being copied
		if (Option::get(Group::MODULE_ID, Group::CHECKER_OPTION . $groupId, '') == 'Y')
		{
			return;
		}

		// tool is available on the portal
		if (
			Loader::includeModule('intranet')
			&& !Restriction\ToolAvailabilityManager::getInstance()->check('knowledge_base')
		)
		{
			return;
		}

		// feature is active for the group
		$activeFeatures = \CSocNetFeatures::getActiveFeaturesNames(SONET_ENTITY_GROUP, $groupId);
		if (!is_array($activeFeatures) || !array_key_exists(self::SETTINGS_CODE, $activeFeatures))
		{
			return;
		}

		// tariff limits
		if (!Restriction\Manager::isAllowed('limit_crm_free_knowledge_base_project'))
		{
			return;
		}

		// binding exists and is readable
		$binding = self::getBindingRow($groupId, false);
		if (empty($binding) || !self::canPerformOperation($groupId, Rights::ACCESS_TYPES['read']))
		{
			return;
		}

		$requestedPage = $request->get('page');
		$url = self::resolveKnowledgeDeepLinkUrl(
			$binding['PUBLIC_URL'],
			is_string($requestedPage) ? $requestedPage : null
		);
		self::insertSliderOpenScript($url);
	}

	/**
	 * Returns url of knowledge base to open in the slider.
	 * Requested page is accepted only as a relative path inside the public url of the same knowledge base.
	 * @param string $publicUrl Public url of the group knowledge base.
	 * @param string|null $requestedPage Page path from the request.
	 * @return string
	 */
	public static function resolveKnowledgeDeepLinkUrl(string $publicUrl, ?string $requestedPage): string
	{
		$page = trim((string)$requestedPage);
		if ($page === '')
		{
			return $publicUrl;
		}

		// relative path only, '//host/...' leads to the external host
		if (!str_starts_with($page, '/') || str_starts_with($page, '//'))
		{
			return $publicUrl;
		}

		$basePath = (new \Bitrix\Main\Web\Uri($publicUrl))->getPath();
		if ($basePath === '')
		{
			return $publicUrl;
		}
		$basePath = rtrim($basePath, '/') . '/';

		// public url degraded to the site root bounds nothing
		if ($basePath === '/')
		{
			return $publicUrl;
		}

		$pagePath = (new \Bitrix\Main\Web\Uri($page))->getPath();
		if (self::hasParentDirectorySegment($pagePath))
		{
			return $publicUrl;
		}

		return str_starts_with($pagePath, $basePath) ? $pagePath : $publicUrl;
	}

	/**
	 * Returns true, if path contains a parent directory segment and can be resolved outside of its own prefix.
	 * Separators and dots are decoded first: browser and server resolve the encoded ones on opening.
	 * @param string $path Url path.
	 * @return bool
	 */
	private static function hasParentDirectorySegment(string $path): bool
	{
		$segments = preg_split('#[/\\\\]#', rawurldecode($path));

		return in_array('..', $segments, true);
	}

	/**
	 * Inserts script for opening url in the slider into the current page.
	 * @param string $url Url for opening.
	 * @return void
	 */
	private static function insertSliderOpenScript(string $url): void
	{
		if ($url === '')
		{
			return;
		}

		$asset = \Bitrix\Main\Page\Asset::getInstance();
		$asset->addString(
			$asset->insertJs(
				'BX.ready(function(){BX.SidePanel.Instance.open(' . \Bitrix\Main\Web\Json::encode($url) . ');});',
				'',
				true
			)
		);
	}

	/**
	 * Returns group path by id.
	 * @param int $groupId Group id.
	 * @param string|null $pagePath Page of landing.
	 * @param bool $generalPath Returns only general path of group.
	 * @return string
	 */
	public static function getTabUrl(int $groupId, ?string $pagePath = null, bool $generalPath = false): ?string
	{
		static $groupPath = null;

		if ($groupPath === null)
		{
			$groupPath = Option::get('socialnetwork', 'group_path_template', '', SITE_ID);
			if (mb_substr($groupPath, -1) == '/')
			{
				$groupPath .= 'general/';
			}
		}

		if ($groupId && $groupPath)
		{
			$groupPath = str_replace('#group_id#', $groupId, $groupPath);
		}

		if ($generalPath)
		{
			return $groupPath;
		}

		if ($groupId && $groupPath)
		{
			$uri = new \Bitrix\Main\Web\Uri($groupPath);
			$uri->addParams([
				'tab' => self::SETTINGS_CODE_SHORT
			]);
			if ($pagePath)
			{
				$uri->addParams([
					'page' => $pagePath
				]);
			}
			return $uri->getUri();
		}

		return null;
	}

	/**
	 * Returns true, if current user is member of group.
	 *
	 * @param int $groupId Group id.
	 * @return bool
	 */
	public static function userInGroup(int $groupId): bool
	{
		if (\Bitrix\Main\Loader::includeModule('socialnetwork'))
		{
			return \CSocNetUserToGroup::getUserRole(
				Manager::getUserId(),
				$groupId
			) <= SONET_ROLES_USER;
		}

		return false;
	}

	/**
	 * Returns true if current user for specified sonet group can perform specified operation.
	 *
	 * @param int $groupId Group id.
	 * @param string $operation Operation code.
	 * @see \Bitrix\Landing\Rights::ACCESS_TYPES
	 * @return bool
	 */
	public static function canPerformOperation(int $groupId, string $operation): bool
	{
		if ($groupId && \Bitrix\Main\Loader::includeModule('socialnetwork'))
		{
			return \CSocNetFeaturesPerms::canPerformOperation(
				Manager::getUserId(),
				SONET_ENTITY_GROUP,
				$groupId,
				self::SETTINGS_CODE,
				$operation
			);
		}

		return false;
	}

	/**
	 * Returns true if current user for specified sonet group can create new binding.
	 *
	 * @param int $groupId Group id.
	 * @return bool
	 */
	public static function canCreateNewBinding(int $groupId): bool
	{
		$operation = Rights::ACCESS_TYPES['edit'];
		return self::userInGroup($groupId) && self::canPerformOperation($groupId, $operation);
	}

	/**
	 * On social network group delete.
	 * @param int $groupId Group id.
	 * @return void
	 */
	public static function onSocNetGroupDelete($groupId)
	{
		\Bitrix\Landing\Site\Type::setScope(
			\Bitrix\Landing\Site\Type::SCOPE_CODE_GROUP
		);
		$bindings = Binding\Group::getList($groupId);
		foreach ($bindings as $binding)
		{
			if ($binding['ENTITY_TYPE'] == Binding\Group::ENTITY_TYPE_SITE)
			{
				Site::delete($binding['ENTITY_ID'], true)->isSuccess();
			}
		}
	}

	/**
	 * Local tool for resolve group id by group feature id.
	 *
	 * @param int $featureId Feature id.
	 * @return int|null
	 */
	private static function getGroupIdByFeatureId(int $featureId): ?int
	{
		static $featureToGroup = null;

		if ($featureToGroup === null)
		{
			$res = \CSocNetFeatures::getList(
				[],
				[
					'ENTITY_TYPE' => SONET_ENTITY_GROUP,
					'FEATURE' => self::SETTINGS_CODE,
				],
				false, false,
				['ID', 'ENTITY_ID']
			);
			while ($row = $res->fetch())
			{
				$featureToGroup[$row['ID']] = $row['ENTITY_ID'];
			}
		}

		return $featureToGroup[$featureId] ?? null;
	}
}
