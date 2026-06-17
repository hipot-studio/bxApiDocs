<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper\MapperInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper\NotificationMapperInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Reducer\ExcludeDndUsers;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Reducer\ExcludeMutedUsers;

class NotificationRecipientsResolver extends RecipientsResolver
{

	protected function isAllowedMapper(MapperInterface $mapper): bool
	{
		return $mapper instanceof NotificationMapperInterface;
	}

	protected function getReducersFromNotification(): \Generator
	{
		yield from parent::getReducersFromNotification();
		yield $this->container->get(ExcludeMutedUsers::class);
		yield $this->container->get(ExcludeDndUsers::class);
	}
}
