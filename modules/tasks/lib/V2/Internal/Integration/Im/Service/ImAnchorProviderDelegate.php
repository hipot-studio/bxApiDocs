<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Service;

use Bitrix\Main\Loader;
use Bitrix\Im\V2\Anchor\AnchorProvider;

class ImAnchorProviderDelegate
{
	private ?AnchorProvider $delegate = null;

	public function __construct()
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$this->delegate = new AnchorProvider();
	}

	public function __call($name, $arguments): mixed
	{
		if (null === $this->delegate)
		{
			return null;
		}

		return $this->delegate->$name(...$arguments);
	}

	/**
	 * @param int $userId 
	 * @return array{chatId: int, messageId: int, userId: int, fromUserId: int, type: string, subType: string, parentChatId: int, parentMessageId: int}[]
	 */
	public function getUserAnchors(int $userId): array
	{
		if (null === $this->delegate)
		{
			return [];
		}

		return $this->delegate->getUserAnchors($userId);
	}
}
