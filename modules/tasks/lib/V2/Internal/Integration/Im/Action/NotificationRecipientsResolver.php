<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper\MapperInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper\NotificationMapperInterface;

class NotificationRecipientsResolver extends RecipientsResolver
{

	protected function isAllowedMapper(MapperInterface $mapper): bool
	{
		return $mapper instanceof NotificationMapperInterface;
	}
}
