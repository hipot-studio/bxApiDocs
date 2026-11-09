<?php

namespace Bitrix\Landing\Mobile;

use Bitrix\Landing\Connector;
use Bitrix\Landing\Internals;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Restriction;
use Bitrix\Landing\Site\Type;
use Bitrix\Main\Context;
use Bitrix\Main\Composite\Engine;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\UserAgent\Detector;
use Bitrix\Socialnetwork\UserToGroupTable;

class LegacyKnowledgeAccess
{
	private const CUTOFF_DATE_UTC = '2026-10-01 00:00:00 UTC';
	private const MAX_TIMESTAMP = '253402300799';
	private const GROUP_BATCH_SIZE = 200;

	private static array $allowedByUser = [];
	private static array $allowedByGroup = [];

	/**
	 * Returns the portal's legacy knowledge access for a user.
	 * @param int $userId
	 * @return bool
	 */
	public static function isAllowed(int $userId): bool
	{
		return self::$allowedByUser[$userId] ??= self::resolveAllowed($userId);
	}

	public static function isMenuAllowed(): bool
	{
		return self::isPortalAllowed() !== false && self::hasOrdinaryKnowledge();
	}

	public static function isGroupAllowed(int $userId, int $groupId): bool
	{
		return self::$allowedByGroup[$userId][$groupId] ??= self::resolveGroupAllowed($userId, $groupId);
	}

	/**
	 * Early check before a group site's ID is resolved. A resolved target still needs isRequestAllowed().
	 */
	public static function isGroupPortalRequestAllowed(): bool
	{
		if (!self::isMobileAppRequest())
		{
			return true;
		}

		static::prepareResponse();

		return self::isPortalAllowed() !== false;
	}

	private static function resolveGroupAllowed(int $userId, int $groupId): bool
	{
		if (
			$userId <= 0
			|| $groupId <= 0
			|| self::isPortalAllowed() === false
			|| !self::isGroupKnowledgeEnabled($userId)
		)
		{
			return false;
		}

		$membership = static::getMembershipQuery()
			->setSelect(['GROUP_ID'])
			->where('USER_ID', $userId)
			->where('GROUP_ID', $groupId)
			->whereIn('ROLE', UserToGroupTable::getRolesMember())
			->setLimit(1)
			->fetch()
		;
		if (!$membership)
		{
			return false;
		}

		$candidates = self::getGroupKnowledgeCandidates([(string)$groupId]);

		return $candidates !== [] && self::hasReadableCandidate($userId, $candidates);
	}

	/**
	 * Detects the mobile application from the current request.
	 * @return bool
	 */
	public static function isMobileAppRequest(): bool
	{
		$browser = (new Detector())->detectBrowser(static::getUserAgent());

		return $browser->getName() === 'Bitrix24.Mobile';
	}

	/**
	 * Identifies legacy knowledge site types.
	 * @param string $type
	 * @return bool
	 */
	public static function isLegacyType(string $type): bool
	{
		return $type === Type::SCOPE_CODE_KNOWLEDGE || $type === Type::SCOPE_CODE_GROUP;
	}

	/**
	 * Checks access and prevents caching for a legacy app reader request.
	 * @param string $type
	 * @param int $siteId
	 * @param int $landingId
	 * @return bool
	 */
	public static function isRequestAllowed(string $type = '', int $siteId = 0, int $landingId = 0): bool
	{
		if (!self::isMobileAppRequest())
		{
			return true;
		}
		$target = static::getSiteTarget($siteId, $landingId);
		$actualType = (string)($target['TYPE'] ?? '');
		if (!self::isLegacyType($type) && !self::isLegacyType($actualType))
		{
			return true;
		}

		static::prepareResponse();
		if ($actualType === Type::SCOPE_CODE_GROUP)
		{
			$groupId = static::getGroupIdBySiteId((int)$target['SITE_ID']);

			return self::isGroupAllowed(static::getCurrentUserId(), (int)$groupId);
		}
		if ($type === Type::SCOPE_CODE_GROUP && ($siteId > 0 || $landingId > 0) && $target === null)
		{
			return false;
		}

		return self::isAllowed(static::getCurrentUserId());
	}

	/**
	 * Resolves the actual site type, preferring the landing's site.
	 * @param int $siteId
	 * @param int $landingId
	 * @return string|null
	 */
	public static function getSiteType(int $siteId = 0, int $landingId = 0): ?string
	{
		return static::getSiteTarget($siteId, $landingId)['TYPE'] ?? null;
	}

	private static function getSiteTarget(int $siteId, int $landingId): ?array
	{
		if ($landingId > 0)
		{
			$landing = static::getLandingQuery()
				->setSelect(['SITE_ID', 'TYPE' => 'SITE.TYPE'])
				->where('ID', $landingId)
				->setLimit(1)
				->fetch()
			;

			return $landing ? ['SITE_ID' => (int)$landing['SITE_ID'], 'TYPE' => (string)$landing['TYPE']] : null;
		}

		if ($siteId <= 0)
		{
			return null;
		}

		$site = static::getSiteQuery()
			->setSelect(['ID', 'TYPE'])
			->where('ID', $siteId)
			->setLimit(1)
			->fetch()
		;

		return $site ? ['SITE_ID' => (int)$site['ID'], 'TYPE' => (string)$site['TYPE']] : null;
	}

	/**
	 * Prevents caching the current legacy app reader response.
	 * @return void
	 */
	public static function prepareResponse(): void
	{
		Engine::setUseHTMLCache(false);
		Engine::setEnable(false);
		Context::getCurrent()->getResponse()->getHeaders()->set('Cache-Control', 'private, no-store');
	}

	private static function resolveAllowed(int $userId): bool
	{
		$portalAllowed = self::isPortalAllowed();
		if ($portalAllowed !== true)
		{
			return $portalAllowed === null;
		}

		return self::hasOrdinaryKnowledge() || self::hasReadableGroupKnowledge($userId);
	}

	private static function hasOrdinaryKnowledge(): bool
	{
		$site = static::getSiteQuery()
			->setSelect(['ID'])
			->where('TYPE', Type::SCOPE_CODE_KNOWLEDGE)
			->where('DELETED', 'N')
			->setLimit(1)
			->setCacheTtl(86400)
			->fetch()
		;

		return (bool)$site;
	}

	/**
	 * Null preserves the general reader fallback for an unknown cloud creation date.
	 */
	private static function isPortalAllowed(): ?bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			$createTime = self::normalizeTimestamp(static::getPortalCreateTime());
			if ($createTime === null)
			{
				return null;
			}

			return self::compareTimestamps($createTime, self::getCutoffTimestamp()) < 0;
		}

		return true;
	}

	private static function getCutoffTimestamp(): string
	{
		static $timestamp = null;

		return $timestamp ??= (new \DateTimeImmutable(self::CUTOFF_DATE_UTC))->format('U');
	}

	private static function normalizeTimestamp(mixed $value): ?string
	{
		if ((!is_string($value) && !is_int($value)) || preg_match('/^[0-9]+$/D', (string)$value) !== 1)
		{
			return null;
		}
		$value = ltrim((string)$value, '0');
		if ($value === '' || self::compareTimestamps($value, self::MAX_TIMESTAMP) > 0)
		{
			return null;
		}

		return $value;
	}

	private static function compareTimestamps(string $left, string $right): int
	{
		return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
	}

	private static function isGroupKnowledgeEnabled(int $userId): bool
	{
		if (
			$userId <= 0
			|| !Loader::includeModule('socialnetwork')
			|| !static::isProjectKnowledgeAvailable()
			|| !static::isKnowledgeToolAvailable()
		)
		{
			return false;
		}

		$features = static::getAllowedFeatures();
		if (!in_array(SONET_ENTITY_GROUP, $features[Connector\SocialNetwork::SETTINGS_CODE]['allowed'] ?? [], true))
		{
			return false;
		}

		return true;
	}

	private static function hasReadableGroupKnowledge(int $userId): bool
	{
		if (!self::isGroupKnowledgeEnabled($userId))
		{
			return false;
		}

		$cursor = 0;
		do
		{
			$memberships = static::getMembershipQuery()
				->setSelect(['GROUP_ID'])
				->where('USER_ID', $userId)
				->whereIn('ROLE', UserToGroupTable::getRolesMember())
				->where('GROUP_ID', '>', $cursor)
				->setOrder(['GROUP_ID' => 'ASC'])
				->setLimit(self::GROUP_BATCH_SIZE)
				->exec()
			;
			$groupIds = [];
			while ($membership = $memberships->fetch())
			{
				$cursor = (int)$membership['GROUP_ID'];
				$groupIds[] = (string)$cursor;
			}
			if ($groupIds === [])
			{
				return false;
			}

			$candidates = self::getGroupKnowledgeCandidates($groupIds);
			if ($candidates !== [] && self::hasReadableCandidate($userId, $candidates))
			{
				return true;
			}
		}
		while (count($groupIds) === self::GROUP_BATCH_SIZE);

		return false;
	}

	private static function getGroupKnowledgeCandidates(array $groupIds): array
	{
		$bindings = static::getBindingQuery()
			->setSelect(['BINDING_ID'])
			->registerRuntimeField(new ReferenceField(
				'SITE',
				Internals\SiteTable::class,
				['=this.ENTITY_ID' => 'ref.ID'],
				['join_type' => 'INNER'],
			))
			->where('ENTITY_TYPE', Internals\BindingTable::ENTITY_TYPE_SITE)
			->where('BINDING_TYPE', 'G')
			->whereIn('BINDING_ID', $groupIds)
			->where('SITE.TYPE', Type::SCOPE_CODE_GROUP)
			->where('SITE.DELETED', 'N')
			->exec()
		;
		$candidates = [];
		while ($binding = $bindings->fetch())
		{
			$candidates[(int)$binding['BINDING_ID']] = (int)$binding['BINDING_ID'];
		}

		return array_values($candidates);
	}

	private static function hasReadableCandidate(int $userId, array $groupIds): bool
	{
		$activeIds = array_fill_keys($groupIds, true);
		$features = static::getFeatures(
			[],
			[
				'ENTITY_TYPE' => SONET_ENTITY_GROUP,
				'@ENTITY_ID' => $groupIds,
				'FEATURE' => Connector\SocialNetwork::SETTINGS_CODE,
			],
			false,
			false,
			['ENTITY_ID', 'ACTIVE'],
		);
		foreach ($features as $feature)
		{
			if ($feature['ACTIVE'] === 'N')
			{
				unset($activeIds[(int)$feature['ENTITY_ID']]);
			}
		}
		if ($activeIds === [])
		{
			return false;
		}

		$permissions = static::getReadPermissions(
			$userId,
			SONET_ENTITY_GROUP,
			array_keys($activeIds),
			Connector\SocialNetwork::SETTINGS_CODE,
			'read',
			false,
		);
		foreach ($activeIds as $groupId => $active)
		{
			if (is_array($permissions) && ($permissions[$groupId] ?? false) === true)
			{
				return true;
			}
		}

		return false;
	}

	protected static function getUserAgent(): ?string
	{
		return Context::getCurrent()->getRequest()->getUserAgent();
	}

	protected static function getPortalCreateTime(): mixed
	{
		return \CBitrix24::getCreateTime();
	}

	protected static function getSiteQuery(): \Bitrix\Main\ORM\Query\Query
	{
		return Internals\SiteTable::query();
	}

	protected static function getLandingQuery(): \Bitrix\Main\ORM\Query\Query
	{
		return Internals\LandingTable::query();
	}

	protected static function getBindingQuery(): \Bitrix\Main\ORM\Query\Query
	{
		return Internals\BindingTable::query();
	}

	protected static function getMembershipQuery(): \Bitrix\Main\ORM\Query\Query
	{
		return UserToGroupTable::query();
	}

	protected static function isProjectKnowledgeAvailable(): bool
	{
		return Restriction\Manager::isAllowed('limit_crm_free_knowledge_base_project');
	}

	protected static function isKnowledgeToolAvailable(): bool
	{
		return Restriction\ToolAvailabilityManager::getInstance()->check('knowledge_base');
	}

	protected static function getAllowedFeatures(): array
	{
		return \CSocNetAllowed::GetAllowedFeatures();
	}

	protected static function getFeatures(
		array $order, array $filter, mixed $groupBy, mixed $navigation, array $select,
	): iterable
	{
		$result = \CSocNetFeatures::GetList($order, $filter, $groupBy, $navigation, $select);
		while ($feature = $result->Fetch())
		{
			yield $feature;
		}
	}

	protected static function getReadPermissions(
		int $userId, string $entityType, array $groupIds, string $feature, string $operation, bool $admin,
	): mixed
	{
		return \CSocNetFeaturesPerms::CanPerformOperation(
			$userId, $entityType, $groupIds, $feature, $operation, $admin,
		);
	}

	protected static function getCurrentUserId(): int
	{
		return Manager::getUserId();
	}

	protected static function getGroupIdBySiteId(int $siteId): ?int
	{
		return \Bitrix\Landing\Site\Scope\Group::getGroupIdBySiteId($siteId);
	}
}
