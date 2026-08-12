<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\Note\Async;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Socialnetwork\Collab\Integration\Note\PermissionProjectionService;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

/**
 * Обработчик очереди socialnetwork_collab_note_acl: пересчёт ACL коллекции note
 * по текущему составу коллаба (full-replace). На неуспех — RecoverableMessageException,
 * чтобы очередь повторила (retry_strategy в config/messenger.php).
 */
class CollabNoteAclReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!($message instanceof CollabNoteAclRecalcMessage))
		{
			throw new UnprocessableMessageException($message);
		}

		$result = Container::getInstance()->get(PermissionProjectionService::class)->recalculate(
			$message->collabId,
			$message->privilegedBeforeIds ?? [],
		);
		if (!$result->isSuccess())
		{
			throw new RecoverableMessageException(
				'Failed to recalc note collection ACL for collab '
				. $message->collabId . ': ' . implode('; ', $result->getErrorMessages())
			);
		}
	}
}
