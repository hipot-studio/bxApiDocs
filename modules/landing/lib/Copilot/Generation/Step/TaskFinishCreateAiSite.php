<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Metrika;

/**
 * Final step of the AI site creation scenario.
 * Marks the site as successfully created by AI.
 */
class TaskFinishCreateAiSite extends Finish
{
	public function execute(): bool
	{
		if (!parent::execute())
		{
			return false;
		}

		$this->sendCreateTemplateMetrika();

		return true;
	}

	private function sendCreateTemplateMetrika(): void
	{
		$siteId = (int)($this->siteData->getSiteId() ?? 0);

		try
		{
			(new Metrika\Metrika(
				Metrika\Categories::Site,
				Metrika\Events::createTemplate,
				Metrika\Tools::Site,
			))
				->setType(Metrika\Types::ai)
				->setParam(3, 'siteId', (string)$siteId)
				->send()
			;
		}
		catch (\Throwable)
		{
			// The generation is already finished: analytics must not turn it into an error.
		}
	}
}
