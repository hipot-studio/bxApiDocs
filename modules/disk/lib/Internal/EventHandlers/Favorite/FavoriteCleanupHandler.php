<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\EventHandlers\Favorite;

use Bitrix\Disk\Internal\Repository\FavoriteMarkRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Event;

/**
 * Takes the favorite marks away with the object deleted for good and with the user deleted from the portal.
 * Runs whatever the state of the feature flag: marks must not pile up while the feature is off.
 */
final class FavoriteCleanupHandler
{
	/**
	 * Handler of disk:onAfterDeleteFile and disk:onAfterDeleteFolder, the first parameter is the id of the object.
	 */
	public static function onAfterDeleteObject(Event $event): void
	{
		$objectId = (int)($event->getParameters()[0] ?? 0);
		if ($objectId <= 0)
		{
			return;
		}

		try
		{
			(new FavoriteMarkRepository())->deleteByObjectId($objectId);
		}
		catch (\Throwable $exception)
		{
			self::log('Favorite marks of the object {objectId} were not dropped: {message}', [
				'objectId' => $objectId,
				'message' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}
	}

	/**
	 * Handler of main:OnUserDelete.
	 */
	public static function onUserDelete($userId): void
	{
		$userId = (int)$userId;
		if ($userId <= 0)
		{
			return;
		}

		try
		{
			(new FavoriteMarkRepository())->deleteByUserId($userId);
		}
		catch (\Throwable $exception)
		{
			self::log('Favorite marks of the user {userId} were not dropped: {message}', [
				'userId' => $userId,
				'message' => $exception->getMessage(),
				'exception' => $exception,
			]);
		}
	}

	private static function log(string $message, array $context): void
	{
		LoggerFactory::create(feature: 'favorites')->error($message, $context);
	}
}
