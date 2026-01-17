<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Repository;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Message;
use Bitrix\Tasks\V2\Internal\Integration\Im\Repository\Mapper\MessageMapper;

class MessageRepository implements MessageRepositoryInterface
{
	public function __construct(
		private readonly MessageMapper $messageMapper
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
}
