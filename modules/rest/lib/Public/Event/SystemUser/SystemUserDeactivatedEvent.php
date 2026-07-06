<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Event\SystemUser;

use Bitrix\Main\Event;
use Bitrix\Rest\Internal\Entity\SystemUser;

class SystemUserDeactivatedEvent extends Event
{
	public function __construct(SystemUser $systemUser)
	{
		parent::__construct('rest', 'onSystemUserDeactivated', [
			'systemUser' => $systemUser,
		]);
	}
}