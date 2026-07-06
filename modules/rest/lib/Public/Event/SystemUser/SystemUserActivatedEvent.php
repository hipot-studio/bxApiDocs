<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Event\SystemUser;

use Bitrix\Main\Event;
use Bitrix\Rest\Internal\Entity\SystemUser;
use Bitrix\Rest\Internal\Entity\User;

class SystemUserActivatedEvent extends Event
{
	public function __construct(SystemUser $systemUser, User $originalUser)
	{
		parent::__construct('rest', 'onSystemUserActivated', [
			'originalUser' => $originalUser,
			'systemUser' => $systemUser,
		]);
	}
}