<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\View;

use Bitrix\Im\Model\MessageViewedTable;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Type\DateTime;

class ViewUpdater
{
	public function add(MessageCollection $messages, int $userId): MessageCollection
	{
		$messagesToView = $this->filterMessageToInsert($messages, $userId);

		$insertFields = $this->prepareInsertFields($messagesToView, $userId);
		MessageViewedTable::multiplyInsertWithoutDuplicate($insertFields, ['DEADLOCK_SAFE' => true]);
		$messagesToView->setViewedByOthers()->save(true);

		return $messagesToView;
	}

	public function deleteByMessagesIdsForAll(array $messagesIds): void
	{
		MessageViewedTable::deleteByFilter(['=MESSAGE_ID' => $messagesIds]);
	}

	public function deleteByChatId(int $chatId, int $userId): void
	{
		MessageViewedTable::deleteByFilter(['=CHAT_ID' => $chatId, '=USER_ID' => $userId]);
	}

	private function filterMessageToInsert(MessageCollection $messages, int $userId): MessageCollection
	{
		return $messages->filter(
			fn (Message $message) => !$message->isViewed() && ($message->getAuthorId() !== $userId || $message->getChat()->getType() === \IM_MESSAGE_SYSTEM)
		);
	}

	private function prepareInsertFields(MessageCollection $messages, int $userId): array
	{
		$insertFields = [];
		$dateCreate = new DateTime();

		foreach ($messages as $message)
		{
			$insertFields[] = [
				'USER_ID' => $userId,
				'CHAT_ID' => $message->getChatId(),
				'MESSAGE_ID' => $message->getMessageId(),
				'DATE_CREATE' => $dateCreate,
			];
		}

		return $insertFields;
	}
}
