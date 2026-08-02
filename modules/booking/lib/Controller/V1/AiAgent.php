<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Integration\Bizproc\AiAgentLauncher;

class AiAgent extends BaseController
{
	private AiAgentLauncher $aiAgentLauncher;

	protected function init()
	{
		parent::init();

		$this->aiAgentLauncher = Container::getAiAgentLauncher();
	}

	public function launchAction(): array|null
	{
		$result = $this->aiAgentLauncher->launch();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [];
	}
}
