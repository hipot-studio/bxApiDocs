<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

abstract class AbstractNotifyUsers extends AbstractNotify implements SpecificCounterRecipientsInterface
{
	public function __construct(
		protected readonly ?Entity\User $triggeredBy = null,
		protected readonly ?Entity\UserCollection $users = null,
	)
	{
	}

	public function getSpecificCounterRecipients(): UserCollection
	{
		return $this->users;
	}
}
