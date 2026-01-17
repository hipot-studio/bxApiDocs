<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Event;

use Bitrix\Im\V2\Common\Event\BaseEvent;

class AfterReadAllChatsEvent extends BaseEvent
{
	public function __construct(int $userId)
	{
		$parameters = [
			'userId' => $userId,
		];

		parent::__construct('OnAfterReadAllChats', $parameters);
	}

	public function getUserId(): int
	{
		return $this->parameters['userId'];
	}
}
