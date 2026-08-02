<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Api\Enum\Template\CreateSource;

final class ScenarioProcessStarter extends AbstractProcessStarter
{
	protected function getCreateSourceFilter(): CreateSource
	{
		return CreateSource::Scenario;
	}
}
