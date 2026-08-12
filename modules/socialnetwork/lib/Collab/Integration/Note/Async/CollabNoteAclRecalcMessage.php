<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\Note\Async;

use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;
use Bitrix\Socialnetwork\V2\Internal\Async\QueueId;

/**
 * Сообщение очереди: пересчитать ACL коллекции note по текущему составу коллаба.
 *
 * Шлётся из collab update-pipeline (см. UpdateNotePermissionsHandler) при изменении
 * состава/ролей. Обработка —
 * {@see CollabNoteAclReceiver}: пересчитывает права коллекции через note с
 * сохранением ручных выдач (merge, а не слепой full-replace). Идемпотентно,
 * поэтому дубли/ретраи безопасны.
 *
 * $privilegedBeforeIds — снимок владельца/модераторов ДО изменения (из
 * entityBefore хендлера). Нужен пересчёту, чтобы снять персональные U-grant'ы
 * ровно у тех, кто утратил привилегию (понижение/смена владельца/удаление),
 * не трогая ручные права рядовых участников. `null` (legacy-сообщение) → пустой
 * снимок, ничего не снимается.
 *
 * ItemIdParam == collabId → обработка сообщений одного коллаба сериализуется.
 */
class CollabNoteAclRecalcMessage extends AbstractMessage
{
	/**
	 * @param list<int>|null $privilegedBeforeIds
	 */
	public function __construct(
		public readonly int $collabId,
		public readonly ?array $privilegedBeforeIds = null,
	)
	{
	}

	public function sendToQueue(): void
	{
		$this->send(QueueId::CollabNoteAcl->value, [new ItemIdParam('collab_note_acl_' . $this->collabId)]);
	}
}
