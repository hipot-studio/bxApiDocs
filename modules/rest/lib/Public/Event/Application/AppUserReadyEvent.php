<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Event\Application;

use Bitrix\Main\Event;

class AppUserReadyEvent extends Event
{
	public function __construct(array $systemUserData)
	{
		parent::__construct('rest', 'OnRestAppUserReady', $systemUserData);
	}
}