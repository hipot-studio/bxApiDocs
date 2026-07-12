<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Repository;

use Bitrix\Im\V2\MessageCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Message;
use Bitrix\Tasks\V2\Internal\Integration\Im\Repository\Mapper\MessageMapper;

class MessageRepository implements MessageRepositoryInterface
{
	public function __construct(
		private readonly MessageMapper $messageMapper,
	)
	{
	}

	public function getById(int $messageId): ?Message
	{
		if (!Loader::includeModule('im'))
		{
			return null;
		}

		$message = new \Bitrix\Im\V2\Message();

		$loadResult = $message->load($messageId);
		if (!$loadResult->isSuccess())
		{
			return null;
		}

		return $this->messageMapper->mapToEntity(
			message: $message,
		);
	}

	public function tailByChatIdWithoutSystemMessages(int $chatId, int $limit, ?int $lastId = null): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		$filter = [
			'CHAT_ID' => $chatId,
			'WITHOUT_SYSTEM_MESSAGE' => true,
		];

		if ($lastId !== null)
		{
			$filter['LAST_ID'] = $lastId;
		}

		$messages = MessageCollection::find(
			filter: $filter,
			order: ['ID' => 'DESC'],
			limit: $limit,
			select: ['MESSAGE', 'DATE_CREATE', 'AUTHOR_ID'],
		);

		$result = [];

		/** @var \Bitrix\Im\V2\Message $message */
		foreach ($messages as $message)
		{
			$result[] = [
				'TEXT' => $message->getMessage(),
				'CREATED_DATE' => $message->getDateCreate(),
				'USER_ID' => $message->getAuthorId(),
			];
		}

		return $result;
	}

	public function getChatLastMessageDate(int $chatId): ?DateTime
	{
		if (!Loader::includeModule('im'))
		{
			return null;
		}

		$messages = MessageCollection::find(
			filter: ['CHAT_ID' => $chatId, 'WITHOUT_SYSTEM_MESSAGE' => true],
			order: ['ID' => 'DESC'],
			limit: 1,
			select: ['DATE_CREATE'],
		);

		return $messages->getAny()?->getDateCreate();
	}
}
