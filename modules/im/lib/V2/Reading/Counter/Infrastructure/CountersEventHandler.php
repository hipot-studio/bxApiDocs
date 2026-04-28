<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Infrastructure;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Reading\Counter\Infrastructure\Agent\DeleteFiredUserAgent;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;

use Bitrix\Main\DI\ServiceLocator;

class CountersEventHandler
{
	public function __construct(
		protected readonly CountersCache $cache
	) {}

	public function onMuteChanged(int $chatId, int $userId, bool $isMuted): void
	{
		$isMutedString = $isMuted ? 'Y' : 'N';
		MessageUnreadTable::updateBatch(
			['IS_MUTED' => $isMutedString],
			['=CHAT_ID' => $chatId, '=USER_ID' => $userId]
		);
		$this->cache->remove($userId);
	}

	public static function onAfterUserUpdate(array $fields): void
	{
		ServiceLocator::getInstance()->get(self::class)->handleUserUpdate($fields);
	}

	protected function handleUserUpdate(array $fields): void
	{
		if (!isset($fields['ACTIVE']))
		{
			return;
		}

		if ($fields['ACTIVE'] === 'N')
		{
			$this->registerDeletionAgent((int)$fields['ID']);
		}
		else
		{
			$this->removeDeletionAgent((int)$fields['ID']);
		}
	}

	protected function registerDeletionAgent(int $userId): void
	{
		DeleteFiredUserAgent::register($userId);
	}

	protected function removeDeletionAgent(int $userId): void
	{
		DeleteFiredUserAgent::unregister($userId);
	}
}
