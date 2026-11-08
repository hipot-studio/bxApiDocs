<?php
namespace Bitrix\Landing\Site\Scope;

use Bitrix\Landing\Domain;
use Bitrix\Landing\Connector\SocialNetwork;
use Bitrix\Landing\Internals\BindingTable;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Restriction;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Role;
use Bitrix\Landing\Site as LandingSite;
use Bitrix\Landing\Site\Scope;
use Bitrix\Landing\Transfer\Requisite\FinishRedirectLinkDto;
use Bitrix\Main\Loader;

class Group extends Scope
{
	/**
	 * Group id bound to a site, by the site id. The binding belongs to the site itself, so the same
	 * answer serves every user and both modes of the access check.
	 * @var array<int, int|null>
	 */
	private static array $groupIdCache = [];

	/**
	 * Operations of a site, by the site id and by the user the answer was counted for: the operations
	 * come from the rights of the current user on the group, and the user of the process may change
	 * (console and agent scenarios).
	 * @var array<int, array<int, array<string, bool>>>
	 */
	private static array $operationsCache = [];

	/**
	 * Method for first time initialization scope.
	 * @param array $params Additional params.
	 * @return void
	 */
	public static function init(array $params = [])
	{
		if (!Restriction\Manager::isAllowed('limit_crm_free_knowledge_base_project'))
		{
			return;
		}
		parent::init($params);
		Role::setExpectedType(self::$currentScopeId);
	}

	/**
	 * Returns publication path string.
	 * @return string
	 */
	public static function getPublicationPath()
	{
		if (\Bitrix\Landing\Connector\Mobile::isMobileHit())
		{
			return '/mobile/knowledge/group/';
		}

		return '/knowledge/group/';
	}

	/**
	 * Return general key for site path.
	 * @return string
	 */
	public static function getKeyCode()
	{
		return 'CODE';
	}

	/**
	 * Returns domain id for new site.
	 * @return int
	 */
	public static function getDomainId()
	{
		if (!Manager::isB24())
		{
			return Domain::getCurrentId();
		}
		return 0;
	}

	/**
	 * Returns filter value for 'TYPE' key.
	 * @return string
	 */
	public static function getFilterType()
	{
		return self::getCurrentScopeId();
	}

	/**
	 * Returns array of hook's codes, which excluded by scope.
	 * @return array
	 */
	public static function getExcludedHooks(): array
	{
		return [
			'B24BUTTON',
			'COPYRIGHT',
			'CSSBLOCK',
			'FAVICON',
			'GACOUNTER',
			'GTM',
			'HEADBLOCK',
			'METAGOOGLEVERIFICATION',
			'METAMAIN',
			'METAROBOTS',
			'METAYANDEXVERIFICATION',
			'PIXELFB',
			'PIXELVK',
			'ROBOTS',
			'SETTINGS',
			'SPEED',
			'YACOUNTER',
			'COOKIES'
		];
	}

	/**
	 * If for site id exists group, then returns group id.
	 * @param int $siteId Site id.
	 * @param bool $checkAccess If true check access to binding group.
	 * @return int|null
	 */
	public static function getGroupIdBySiteId(int $siteId, bool $checkAccess = false): ?int
	{
		if (!array_key_exists($siteId, self::$groupIdCache))
		{
			self::$groupIdCache[$siteId] = self::fetchGroupIdBySiteId($siteId);
		}

		$groupId = self::$groupIdCache[$siteId];
		if ($groupId === null || !$checkAccess)
		{
			return $groupId;
		}

		return self::canReadGroup($groupId) ? $groupId : null;
	}

	/**
	 * @internal Resets the runtime caches built around the group of the site.
	 * @param int|null $siteId Site id, all the sites if null.
	 * @return void
	 */
	public static function resetRuntimeCache(?int $siteId = null): void
	{
		if ($siteId === null)
		{
			self::$groupIdCache = [];
			self::$operationsCache = [];
		}
		else
		{
			unset(self::$groupIdCache[$siteId], self::$operationsCache[$siteId]);
		}
	}

	/**
	 * Reads the group bound to the site, without the runtime cache.
	 * @param int $siteId Site id.
	 * @return int|null
	 */
	private static function fetchGroupIdBySiteId(int $siteId): ?int
	{
		$res = BindingTable::getList([
			'select' => [
				'BINDING_ID'
			],
			'filter' => [
				'=ENTITY_TYPE' => BindingTable::ENTITY_TYPE_SITE,
				'=BINDING_TYPE' => 'G',
				'ENTITY_ID' => $siteId
			]
		]);
		if ($row = $res->fetch())
		{
			return (int)$row['BINDING_ID'];
		}

		return null;
	}

	/**
	 * Checks the read access of the current user to the knowledge base of the group.
	 * @param int $groupId Group id.
	 * @return bool
	 */
	private static function canReadGroup(int $groupId): bool
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return true;
		}

		// the answer is already kept for the hit by CSocNetFeaturesPerms::CanPerformOperation()
		return (bool)\CSocNetFeaturesPerms::CanPerformOperation(
			Manager::getUserId(),
			SONET_ENTITY_GROUP,
			$groupId,
			SocialNetwork::SETTINGS_CODE,
			'read'
		);
	}

	/**
	 * Scoped method for returning available operations of site.
	 * @param int $siteId Site id.
	 * @see \Bitrix\Landing\Rights::getOperationsForSite
	 * @return array
	 */
	public static function getOperationsForSite(int $siteId): array
	{
		$userId = Manager::getUserId();

		if (!isset(self::$operationsCache[$siteId][$userId]))
		{
			$groupId = self::getGroupIdBySiteId($siteId) ?: 0;
			$canEdit = SocialNetwork::canPerformOperation($groupId, Rights::ACCESS_TYPES['edit']);
			$operations = [
				'read' => false,//see below
				'edit' => $canEdit,
				'public' => $canEdit,
				'sett' => SocialNetwork::canPerformOperation($groupId, Rights::ACCESS_TYPES['sett']),
				'delete' => SocialNetwork::canPerformOperation($groupId, Rights::ACCESS_TYPES['delete']),
			];

			if (array_sum($operations))
			{
				$operations['read'] = true;
			}

			self::$operationsCache[$siteId][$userId] = $operations;
		}

		return self::$operationsCache[$siteId][$userId];
	}

	/**
	 * @inheritdoc
	 */
	public static function onTransferFinishRedirectUrlGet(int $siteId): ?FinishRedirectLinkDto
	{
		return new FinishRedirectLinkDto(
			href: LandingSite::getPublicUrl($siteId),
		);
	}
}
