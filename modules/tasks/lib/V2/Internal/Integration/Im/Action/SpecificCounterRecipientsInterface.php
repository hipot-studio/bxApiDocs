<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

interface SpecificCounterRecipientsInterface
{
	public function getSpecificCounterRecipients(): UserCollection;
}
