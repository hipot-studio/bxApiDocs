<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Main\Localization\Loc;

/**
 * Tells the participants of an interrupted editing session that it is over.
 *
 * The portal ends such a session on its own, minutes after the editor stopped answering, and nobody at
 * the other end is told anything by the editor. The document is unlocked by then, so the message names
 * the only move which brings the editing back: open the document again.
 */
// Not final: sendNotify() is the seam tests replace to read who is told and with which text.
class InterruptedSessionNotifier
{
	private const NOTIFY_EVENT = 'files';
	private const NOTIFY_TAG_PREFIX = 'DISK|ONLYOFFICE_INTERRUPTED|';

	/**
	 * @param int[] $userIds participants of the edit sessions of the key; the ones who may read the file
	 *        are told, the rest are skipped.
	 */
	public function notify(File $object, array $userIds): void
	{
		$recipientIds = array_unique(array_filter(array_map('intval', $userIds)));
		if (!$recipientIds)
		{
			return;
		}

		$url = Driver::getInstance()->getUrlManager()->getPathFileDetail($object);
		$name = $object->getName();
		$storage = $object->getStorage();
		$authorId = (int)$object->getCreatedBy();

		foreach ($recipientIds as $recipientId)
		{
			if (!$storage || !$object->canRead($storage->getSecurityContext($recipientId)))
			{
				continue;
			}

			$this->sendNotify($recipientId, [
				'NOTIFY_TYPE' => 'IM_NOTIFY_FROM',
				// A notification of the portal itself has no author to name, and one without an author at
				// all is refused by the messenger - so it comes from the owner of the document, the way
				// the notifications of Disk do.
				'FROM_USER_ID' => $authorId > 0 ? $authorId : $recipientId,
				'NOTIFY_EVENT' => self::NOTIFY_EVENT,
				'NOTIFY_TAG' => self::NOTIFY_TAG_PREFIX . $object->getId() . '|' . $recipientId,
				'NOTIFY_MESSAGE' => $this->makeMessage($name, $url),
				'NOTIFY_MESSAGE_OUT' => $this->makeMessageOut($name, $url),
			]);
		}
	}

	/**
	 * Handing the message to the messenger: who is told and with which text is decided above.
	 */
	protected function sendNotify(int $recipientId, array $fields): void
	{
		Driver::getInstance()->sendNotify($recipientId, $fields);
	}

	/**
	 * The text is built per recipient language: the notification reaches people of different languages.
	 */
	private function makeMessage(string $name, string $url): \Closure
	{
		return static function (?string $languageId = null) use ($name, $url): string {
			$link = '<a href="' . htmlspecialcharsbx($url) . '">' . htmlspecialcharsbx($name) . '</a>';

			return (string)Loc::getMessage(
				'DISK_ONLYOFFICE_INTERRUPTED_SESSION_NOTIFICATION',
				['#NAME#' => $link],
				$languageId,
			);
		};
	}

	private function makeMessageOut(string $name, string $url): \Closure
	{
		return static function (?string $languageId = null) use ($name, $url): string {
			$message = (string)Loc::getMessage(
				'DISK_ONLYOFFICE_INTERRUPTED_SESSION_NOTIFICATION',
				['#NAME#' => $name],
				$languageId,
			);

			return $message . "\n" . $url;
		};
	}
}
