<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Message\CounterService;

/**
 * @method array getForEachChat(?array $chatIds = null)
 * @method array getByChatForEachUsers(int $chatId, array $userIds)
 *
 * @see \Bitrix\Im\V2\Message\CounterService::getForEachChat()
 * @see \Bitrix\Im\V2\Message\CounterService::getByChatForEachUsers()
 */
class ImMessageCounterServiceDelegate extends AbstractServiceDelegate
{
	public function __construct(?int $userId = null)
	{
		parent::__construct($userId);
	}

	protected function createDelegate(...$arguments): CounterService
	{
		return new CounterService(...$arguments);
	}
}
