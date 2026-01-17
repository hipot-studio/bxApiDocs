<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Reducer;

use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;

class ExcludeNonUniqueUsers implements ReducerInterface
{
	public function __invoke(RecipientsResolver $context): void
	{
		$context->recipients = $context->recipients->unique();
	}
}
