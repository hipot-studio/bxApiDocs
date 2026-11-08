<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;

final class AppChatUserSyncMessage extends AbstractMessage
{
	public function __construct(
		public int $userId,
		public int $afterItemId = 0,
		public int $attemptCount = 0,
	) {
		if ($this->userId <= 0)
		{
			throw new \InvalidArgumentException('User id must be positive');
		}
		if ($this->afterItemId < 0)
		{
			throw new \InvalidArgumentException('Item cursor must not be negative');
		}
		if ($this->attemptCount < 0)
		{
			throw new \InvalidArgumentException('Attempt count must not be negative');
		}
	}

	public static function createFromData(array $data): MessageInterface
	{
		try
		{
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
