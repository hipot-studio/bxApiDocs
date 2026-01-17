<?php

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Reducer;

use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;

interface ReducerInterface
{
	public function __invoke(RecipientsResolver $context): void;
}
