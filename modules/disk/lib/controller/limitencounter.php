<?php
declare(strict_types=1);

namespace Bitrix\Disk\controller;

use Bitrix\Disk\Internal\Enum\LimitEncounterType;
use Bitrix\Disk\Internal\Service\LimitEncounter\DocumentEditSession\LimitEncounterThresholdProcessor;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;

class LimitEncounter extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			new Authentication(),
			new Csrf(),
			new HttpMethod([HttpMethod::METHOD_POST]),
		];
	}

	public function documentEditSessionAction(LimitEncounterThresholdProcessor $handler): void
	{
		$handler->process(LimitEncounterType::DocumentEditSession);
	}
}
