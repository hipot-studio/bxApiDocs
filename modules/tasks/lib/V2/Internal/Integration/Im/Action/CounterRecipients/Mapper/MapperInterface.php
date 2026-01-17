<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper;

use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;

interface MapperInterface
{
	public function __invoke(RecipientsResolver $context): void;
}
