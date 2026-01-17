<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Reducer;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;

class ExcludeTriggeredUser implements ReducerInterface
{
	public function __invoke(RecipientsResolver $context): void
	{
		$triggeredBy = $context->notification->getTriggeredBy();

		if ($triggeredBy === null)
		{
			return;
		}

		$context->recipients = $context->recipients->filter(fn (Entity\User $user): bool => $user->isNotEquals($triggeredBy));
	}
}
