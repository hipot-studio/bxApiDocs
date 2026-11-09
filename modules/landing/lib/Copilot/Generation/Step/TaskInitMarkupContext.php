<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\MarkupTaskStep;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;

/**
 * Context init of the markup scenario. Unlike TaskInitAiSiteContext there is no user
 * prompt to require: the source of the build is the submitted package. Only its reference
 * is checked here; the files are read by the steps that use them.
 */
class TaskInitMarkupContext extends MarkupTaskStep
{
	protected function doExecute(): bool
	{
		if ((new MarkupSiteState($this->generation))->getPackageReference() === null)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'No markup package was submitted.',
			);
		}

		return true;
	}
}
