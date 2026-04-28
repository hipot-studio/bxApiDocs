<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Bitrix24\Integrator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

class IntegratorLimitControl extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!Integrator::isMoreIntegratorsAvailable())
		{
			$this->addError(new Error(
				Loc::getMessage('INTRANET_INTEGRATOR_LIMIT_CONTROL_ERROR_NO_MORE_INTEGRATORS'))
			);

			return new EventResult(EventResult::ERROR, null, 'bitrix24', $this);
		}

		return null;
	}
}
