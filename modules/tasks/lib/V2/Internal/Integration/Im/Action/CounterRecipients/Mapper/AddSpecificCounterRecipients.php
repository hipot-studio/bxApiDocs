<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper;

use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\SpecificCounterRecipientsInterface;

class AddSpecificCounterRecipients implements CounterMapperInterface
{
	public function __invoke(RecipientsResolver $context): void
	{
		if ($context->notification instanceof SpecificCounterRecipientsInterface)
		{
			$context->recipients->merge($context->notification->getSpecificCounterRecipients());
		}
	}
}
