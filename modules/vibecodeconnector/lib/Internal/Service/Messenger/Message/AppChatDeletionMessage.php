<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;

final class AppChatDeletionMessage extends AbstractMessage
{
	public function __construct(
		public int $chatId,
		public int $ownerId,
		public int $catalogItemId,
		public int $attemptCount = 0,
		public bool $onlyIfUnbound = false,
	) {
		if ($this->chatId <= 0)
		{
			throw new \InvalidArgumentException('Chat id must be positive');
		}
		if ($this->ownerId <= 0)
		{
			throw new \InvalidArgumentException('Owner id must be positive');
		}
		if ($this->catalogItemId <= 0)
		{
			throw new \InvalidArgumentException('Catalog item id must be positive');
		}
	}

	public static function createFromData(array $data): MessageInterface
	{
		try
		{
			$data['onlyIfUnbound'] = (bool)($data['onlyIfUnbound'] ?? false);

			return parent::createFromData($data);
		}
		catch (ArgumentException $exception)
		{
			throw $exception;
		}
		catch (\InvalidArgumentException|\TypeError|\ValueError $exception)
		{
			throw new ArgumentException($exception->getMessage(), 'data', $exception);
		}
	}
}
