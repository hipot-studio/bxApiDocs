<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\Internal\Service\CollaborationMenuBalloon as CollaborationMenuBalloonService;
use Bitrix\Main\Engine\Controller;

class CollaborationMenuBalloon extends Controller
{
	/** @ajaxAction intranet.v2.CollaborationMenuBalloon.getDecision */
	public function getDecisionAction(int $onMessengerPage = 0): ?string
	{
		return CollaborationMenuBalloonService::getDecisionForCurrentRequest($onMessengerPage === 1);
	}
}
