<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Im;

use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Im\V2\Chat\ExternalChat\Config;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersAddEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Event\RegisterTypeEvent;
use Bitrix\Main\EventResult;

/**
 * @internal
 */
final class SyncChatType
{
	public const ENTITY_TYPE = 'CRM_SYNC';

	public static function onRegisterType(RegisterTypeEvent $event): EventResult
	{
		return new EventResult(EventResult::SUCCESS, [
			'type' => self::ENTITY_TYPE,
			'config' => new Config(
				hasOwnRecentSection: true,
				permissions: [],
				isAutoJoinEnabled: true,
				requiresParentMembership: false,
				useDefaultRecentSection: true,
			),
		]);
	}

	public static function onFilterUsersByAccess(FilterUsersByAccessEvent $event): EventResult
	{
		$activityId = (int)$event->getChat()->getEntityId();
		$owner = $activityId > 0 ? self::getActivityOwner($activityId) : null;
		if ($owner === null)
		{
			return new EventResult(EventResult::SUCCESS, ['userIds' => []]);
		}

		[$ownerTypeId, $ownerId] = $owner;
		$usersWithAccess = [];
		foreach ($event->getUserIds() as $userId)
		{
			$userId = (int)$userId;
			if (Container::getInstance()->getUserPermissions($userId)->item()->canRead($ownerTypeId, $ownerId))
			{
				$usersWithAccess[] = $userId;
			}
		}

		return new EventResult(EventResult::SUCCESS, ['userIds' => $usersWithAccess]);
	}

	public static function onBeforeUsersAdd(BeforeUsersAddEvent $event): EventResult
	{
		$config = $event->getAddUsersConfig();
		if ($config->byAutoJoin)
		{
			$config = $config
				->addHiddenUserIds($event->getUserIds())
				->setWithMessage(false)
			;
		}

		return new EventResult(EventResult::SUCCESS, ['config' => $config]);
	}

	/**
	 * @return array{int, int}|null
	 */
	private static function getActivityOwner(int $activityId): ?array
	{
		$row = ActivityTable::query()
			->setSelect(['OWNER_TYPE_ID', 'OWNER_ID'])
			->where('ID', $activityId)
			->setLimit(1)
			->fetch()
		;
		if (!$row)
		{
			return null;
		}

		return [(int)$row['OWNER_TYPE_ID'], (int)$row['OWNER_ID']];
	}
}
