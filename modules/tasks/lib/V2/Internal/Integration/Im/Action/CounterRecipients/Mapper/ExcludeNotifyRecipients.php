<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper;

use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\ExcludeNotifyRecipientsInterface;

class ExcludeNotifyRecipients implements NotificationMapperInterface
{
	public function __invoke(RecipientsResolver $context): void
	{
		if ($context->notification instanceof ExcludeNotifyRecipientsInterface)
		{
			foreach ($context->notification->getExcludedNotifyRecipients()->getIds() as $userId) {
				$context->recipients->remove($userId);
			}
		}
	}
}
