<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Event;

use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Common\Event\BaseEvent;

class AfterReadAllChatsByTypeEvent extends BaseEvent
{
	public function __construct(int $userId, Type $type)
	{
		$parameters = [
			'userId' => $userId,
			'chatType' => $type->extendedType,
		];

		$typeForEventName = ucfirst($type->getExtendedType());

		parent::__construct('OnAfterReadAllChatsByType' . $typeForEventName, $parameters);
	}

	public function getUserId(): int
	{
		return $this->parameters['userId'];
	}

	public function getChatType(): string
	{
		return $this->parameters['chatType'];
	}
}
