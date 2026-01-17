<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper\CounterMapperInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper\MapperInterface;

class CounterRecipientsResolver extends RecipientsResolver
{
	protected function isAllowedMapper(MapperInterface $mapper): bool
	{
		return $mapper instanceof CounterMapperInterface;
	}
}
