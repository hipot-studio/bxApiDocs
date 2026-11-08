<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMembershipPhase;

final class AppChatItemSyncMessage extends AbstractMessage
{
	public function __construct(
		public int $catalogItemId,
		public int $revision = 0,
		public int $afterUserId = 0,
		public string $phase = AppChatMembershipPhase::Audience->value,
		public int $attemptCount = 0,
	) {
		if ($this->catalogItemId <= 0)
		{
			throw new \InvalidArgumentException('Catalog item id must be positive');
		}
		if ($this->revision < 0)
		{
			throw new \InvalidArgumentException('Revision must not be negative');
		}
		if ($this->afterUserId < 0)
		{
			throw new \InvalidArgumentException('User cursor must not be negative');
		}
		if (AppChatMembershipPhase::tryFrom($this->phase) === null)
		{
			throw new \InvalidArgumentException('Unknown membership phase');
		}
		if ($this->attemptCount < 0)
		{
			throw new \InvalidArgumentException('Attempt count must not be negative');
		}
	}

	public function isFullPass(): bool
	{
		return $this->getPhase() === AppChatMembershipPhase::Audience && $this->afterUserId === 0;
	}

	public function getPhase(): AppChatMembershipPhase
	{
		return AppChatMembershipPhase::from($this->phase);
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
