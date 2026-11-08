<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class ConflictNotifier
{
	/** @param DocumentSession[] $sessions */
	public function notify(File $original, File $copy, array $sessions): Result
	{
		$ownerId = (int)$copy->getCreatedBy();
		if ($ownerId <= 0)
		{
			return (new Result())->addError(new Error('The conflict copy owner is unavailable.', 'vibeoffice_notify_failed'));
		}
		$sessionIds = array_map(static fn(DocumentSession $session): int => (int)$session->getId(), $sessions);
		$publicData = [
			'object' => ['id' => (int)$original->getId()],
			'event' => 'conflict',
			'documentSessionIds' => $sessionIds,
		];
		$original->makeObjectEvent('vibeoffice', $publicData)->sendToObjectChannel();
		$driver = Driver::getInstance();
		$recipientIds = [$ownerId];
		foreach ($sessions as $session)
		{
			$recipientIds[] = (int)$session->getUserId();
		}
		foreach (array_unique($recipientIds) as $recipientId)
		{
			if ($recipientId <= 0 || !$copy->canRead($copy->getStorage()->getSecurityContext($recipientId)))
			{
				continue;
			}
			$url = $driver->getUrlManager()->getPathFileDetail($copy);
			$driver->sendEvent($recipientId, 'vibeoffice', $publicData + ['url' => $url]);
			$message = static function (?string $languageId = null) use ($url): string {
				return (string)Loc::getMessage('DISK_VIBEOFFICE_CONFLICT_NOTIFICATION', [
					'#LINK_START#' => '<a href="' . htmlspecialcharsbx($url) . '">',
					'#LINK_END#' => '</a>',
				], $languageId);
			};
			$messageOut = static function (?string $languageId = null) use ($url): string {
				return (string)Loc::getMessage('DISK_VIBEOFFICE_CONFLICT_NOTIFICATION', [
					'#LINK_START#' => '', '#LINK_END#' => '',
				], $languageId) . "\n" . $url;
			};
			if (!$driver->sendNotify($recipientId, [
				'NOTIFY_EVENT' => 'files',
				'NOTIFY_TYPE' => 'IM_NOTIFY_FROM',
				'FROM_USER_ID' => $ownerId,
				'NOTIFY_TAG' => 'DISK|VIBEOFFICE_CONFLICT|' . $copy->getId() . '|' . $recipientId,
				'NOTIFY_MESSAGE' => $message,
				'NOTIFY_MESSAGE_OUT' => $messageOut,
			]))
			{
				return (new Result())->addError(new Error('Could not send the conflict notification.', 'vibeoffice_notify_failed'));
			}
		}

		return new Result();
	}
}
