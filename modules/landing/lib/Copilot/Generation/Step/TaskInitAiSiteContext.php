<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;

class TaskInitAiSiteContext extends TaskStep
{
	public function execute(): bool
	{
		parent::execute();

		$inputLines = CreateAiSiteState::resolveInputLines($this->generation);
		if ($inputLines === [])
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'CreateAiSite input is empty.',
			);
		}

		CreateAiSiteState::setInput($this->generation, [
			'input' => $inputLines,
		]);

		return true;
	}
}
