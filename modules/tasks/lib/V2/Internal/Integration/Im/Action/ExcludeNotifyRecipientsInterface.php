<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

interface ExcludeNotifyRecipientsInterface
{
	public function getExcludedNotifyRecipients(): UserCollection;
}
