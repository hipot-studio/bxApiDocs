<?php
namespace Bitrix\Im\Integration\Socialnetwork;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Main\Localization\Loc;

class Extranet
{
	const CACHE_TOKEN_TTL = 2592000; // 1 month

	/** @var string[] tags claimed within this process, cleared once by the background job */
	private static array $tagsToClear = [];

	private static bool $tagClearingScheduled = false;

	public static function checkModules()
	{
		return \Bitrix\Main\Loader::includeModule('extranet') && \Bitrix\Main\Loader::includeModule("socialnetwork");
	}

	public static function getGroup($params, $userId = null, bool $filterActiveUser = true)
	{
		if (!self::checkModules())
			return false;

		$params = is_array($params)? $params: [];

		$userId = \Bitrix\Im\Common::getUserId($userId);
		if ($userId <= 0)
		{
			return false;
		}

		$cacheId = 'im_sonet_extranet_v3_'.$userId.($filterActiveUser ? '_active' : '_all');
		$cachePath = '/bx/imc/sonet/extranet'.\Bitrix\Im\Common::getCacheUserPostfix($userId);

		$cache = \Bitrix\Main\Application::getInstance()->getCache();
		$taggedCache = \Bitrix\Main\Application::getInstance()->getTaggedCache();

		if($cache->initCache(self::CACHE_TOKEN_TTL, $cacheId, $cachePath))
		{
			return $cache->getVars();
		}

		$cache->startDataCache();

		$taggedCache->startTagCache($cachePath);

		$db = \CSocNetUserToGroup::GetList(
			array(),
			array(
				"USER_ID" => $userId,
				"<=ROLE" => SONET_ROLES_USER,
				"GROUP_SITE_ID" => \CExtranet::GetExtranetSiteID(),
				"GROUP_ACTIVE" => "Y",
				"GROUP_CLOSED" => "N"
			),
			false,
			false,
			array("ID", "GROUP_ID", "GROUP_NAME")
		);

		$groups = [];
		$groupIds = [];
		while ($row = $db->GetNext(true, false))
		{
			$groupIds[] = $row["GROUP_ID"];
			$groups['SG'.$row['GROUP_ID']] = array(
				'ID' => 'SG'.$row['GROUP_ID'],
				'NAME' => Loc::getMessage('IM_INT_SN_GROUP_EXTRANET', Array('#GROUP_NAME#' => $row['GROUP_NAME'])),
				'USERS' => []
			);

			$taggedCache->registerTag('sonet_group_'.$row['GROUP_ID']);
			$taggedCache->registerTag('sonet_user2group_G'.$row['GROUP_ID']);
		}

		if (count($groups) <= 0)
		{
			// Both contexts were opened above: leaving them open keeps an output buffer and a tag
			// stack entry alive for the rest of the hit, and every tag registered later sticks to
			// this cache path as well.
			$taggedCache->abortTagCache();
			$cache->abortDataCache();

			return false;
		}

		$taggedCache->registerTag('sonet_user2group');

		$taggedCache->endTagCache();

		$filter = [
			'@GROUP_ID' => $groupIds,
			'<=ROLE' => SONET_ROLES_USER,
			'USER_CONFIRM_CODE' => false
		];

		if ($filterActiveUser)
		{
			$filter['USER_ACTIVE'] = 'Y';
		}

		$db = \CSocNetUserToGroup::GetList(
			[],
			$filter,
			false,
			false,
			['ID', 'USER_ID', 'GROUP_ID']
		);
		while ($row = $db->GetNext(true, false))
		{
			if($row['USER_ID'] == $userId || !isset($groups['SG'.$row['GROUP_ID']]))
				continue;

			$groups['SG'.$row['GROUP_ID']]['USERS'][] = $row['USER_ID'];
		}

		$cache->endDataCache($groups);

		return $groups;
	}

	public static function isUserInGroup($userId, $currentUserId = null, bool $filterActiveUser = true)
	{
		$currentUserId = \Bitrix\Im\Common::getUserId($currentUserId);
		if ($currentUserId <= 0)
		{
			return false;
		}

		if ($userId == $currentUserId)
		{
			return true;
		}

		$extranetUsers = self::getAccessibleExtranetUsers($currentUserId, $filterActiveUser);

		return isset($extranetUsers[$userId]);
	}

	public static function filterUserList(array $userList, $currentUserId = null, bool $filterActiveUser = true)
	{
		$currentUserId = \Bitrix\Im\Common::getUserId($currentUserId);
		if ($currentUserId <= 0)
		{
			return false;
		}

		if (empty($userList))
		{
			return [];
		}

		return
			User::getInstance((int)$currentUserId)->isExtranet()
				? self::filterByExtranet((int)$currentUserId, $userList, $filterActiveUser)
				: self::filterByIntranet((int)$currentUserId, $userList, $filterActiveUser)
		;
	}

	protected static function filterByExtranet(int $currentUserId, array $userList, bool $filterActiveUser = true): array
	{
		$extranetUsers = self::getAccessibleExtranetUsers($currentUserId, $filterActiveUser);

		return array_filter($userList, static function($userId) use ($extranetUsers) {
			return isset($extranetUsers[$userId]);
		});
	}

	protected static function filterByIntranet(int $currentUserId, array $userList, bool $filterActiveUser = true): array
	{
		$intranetUsers = [];
		$extranetUsers = [];

		foreach ($userList as $userId)
		{
			if (User::getInstance($userId)->isExtranet())
			{
				$extranetUsers[$userId] = $userId;
			}
			else
			{
				$intranetUsers[$userId] = $userId;
			}
		}

		if (empty($extranetUsers))
		{
			return $intranetUsers;
		}

		$accessibleExtranetUsers = self::getAccessibleExtranetUsers($currentUserId, $filterActiveUser);
		$extranetUsers = array_filter($extranetUsers, static function($userId) use ($accessibleExtranetUsers) {
			return isset($accessibleExtranetUsers[$userId]);
		});

		return array_merge($intranetUsers, $extranetUsers);
	}

	/**
	 * getGroup() hides users with a pending CONFIRM_CODE, so an invited person stays invisible
	 * until the first login empties that code. The code lives in b_user while the cached list is
	 * tagged by group membership alone, so accepting an invitation invalidates nothing on its own.
	 */
	public static function isInviteConfirmation(array $userFields): bool
	{
		if (!array_key_exists('CONFIRM_CODE', $userFields))
		{
			return false;
		}

		// OnAfterUserUpdate fires even when the update itself failed, and the outcome comes in RESULT.
		if (array_key_exists('RESULT', $userFields) && !$userFields['RESULT'])
		{
			return false;
		}

		return (string)$userFields['CONFIRM_CODE'] === '';
	}

	/**
	 * @param int[] $groupIds
	 * @return string[]
	 */
	public static function getGroupCacheTags(array $groupIds): array
	{
		return array_map(static fn($groupId) => 'sonet_user2group_G' . (int)$groupId, $groupIds);
	}

	/**
	 * Drops the cached lists of everyone who shares an extranet group with this user: the entry
	 * is keyed by the asking user, so the owner of a stale list is never the one who just logged in.
	 */
	public static function clearGroupCacheForUser(int $userId): void
	{
		if ($userId <= 0 || !self::checkModules())
		{
			return;
		}

		// Employees are not skipped here: getGroup() caches lists for them too, and an invited
		// employee of an extranet group is hidden from those lists by the very same filter.
		// The query below is narrow and indexed by USER_ID, so the empty case is the cheap exit
		// for mass dismissal and for the invitation stepper.
		$tags = self::claimTagsToClear(self::getGroupCacheTags(self::getGroupIds($userId)));
		if (empty($tags) || self::$tagClearingScheduled)
		{
			return;
		}

		// Clearing a tag renames cache directories of everyone sharing the group, so it leaves the
		// request. A group shared by several users of one batch is cleared once, not once per user.
		self::$tagClearingScheduled = true;

		\Bitrix\Main\Application::getInstance()->addBackgroundJob(static function(): void {
			$taggedCache = \Bitrix\Main\Application::getInstance()->getTaggedCache();
			$failure = null;

			// terminate() exits right after the job queue, so there is no second attempt: a tag
			// that fails must not take the rest of the batch with it.
			foreach (self::takeClaimedTags() as $tag)
			{
				try
				{
					$taggedCache->clearByTag($tag);
				}
				catch (\Throwable $e)
				{
					$failure ??= $e;
				}
			}

			if ($failure !== null)
			{
				throw $failure;
			}
		});
	}

	/**
	 * @param string[] $tags
	 * @return string[] the tags of this batch not claimed earlier in the process
	 */
	public static function claimTagsToClear(array $tags): array
	{
		$newTags = array_values(array_diff($tags, self::$tagsToClear));
		self::$tagsToClear = array_merge(self::$tagsToClear, $newTags);

		return $newTags;
	}

	/**
	 * @return string[] everything claimed so far, leaving the registry ready for the next batch
	 */
	public static function takeClaimedTags(): array
	{
		$tags = self::$tagsToClear;

		self::$tagsToClear = [];
		self::$tagClearingScheduled = false;

		return $tags;
	}

	public static function isTagClearingScheduled(): bool
	{
		return self::$tagClearingScheduled;
	}

	/**
	 * @return int[]
	 */
	protected static function getGroupIds(int $userId): array
	{
		$db = \CSocNetUserToGroup::GetList(
			[],
			[
				'USER_ID' => $userId,
				'<=ROLE' => SONET_ROLES_USER,
				'GROUP_SITE_ID' => \CExtranet::GetExtranetSiteID(),
				'GROUP_ACTIVE' => 'Y',
				'GROUP_CLOSED' => 'N',
			],
			false,
			false,
			['ID', 'GROUP_ID']
		);

		$groupIds = [];
		while ($row = $db->GetNext(true, false))
		{
			$groupIds[] = (int)$row['GROUP_ID'];
		}

		return $groupIds;
	}

	protected static function getAccessibleExtranetUsers(int $currentUserId, bool $filterActiveUser = true): array
	{
		$extranetUsers = [$currentUserId => $currentUserId];
		$groups = self::getGroup([], $currentUserId, $filterActiveUser);

		if (is_array($groups))
		{
			foreach ($groups as $group)
			{
				foreach ($group['USERS'] as $uid)
				{
					$extranetUsers[$uid] = $uid;
				}
			}
		}

		return $extranetUsers;
	}
}
