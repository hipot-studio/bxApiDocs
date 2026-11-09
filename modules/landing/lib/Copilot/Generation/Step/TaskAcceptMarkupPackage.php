<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\MarkupTaskStep;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Markup;
use Bitrix\Landing\Markup\Dev\FailureInjection;
use Bitrix\Landing\Markup\Repair\RepairableCodes;

/**
 * Fail-fast validation of the markup package before anything is created:
 * a package that violates the limits never produces a site skeleton.
 *
 * Hard codes stop the scenario here. Repairable semantic codes pass through:
 * the repair step that follows owns them (ADR R10), so the scenario proceeds
 * instead of failing.
 */
class TaskAcceptMarkupPackage extends MarkupTaskStep
{
	protected function doExecute(): bool
	{
		$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

		$package = (new MarkupSiteState($this->generation))->getPackage();
		if (!$package)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'No markup package was submitted.',
			);
		}

		$result = (new Markup\Validator($this->getLog()))->validate($package);
		if (!$result->isSuccess() && $this->hasUnrepairableErrors($result->getErrors()))
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				implode('; ', $result->getErrorMessages()),
			);
		}

		return true;
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}

	private function getLog(): Log
	{
		return new Log($this->generation->getId());
	}

	/**
	 * @param \Bitrix\Main\Error[] $errors
	 */
	private function hasUnrepairableErrors(array $errors): bool
	{
		foreach ($errors as $error)
		{
			if (!RepairableCodes::isRepairable((string)$error->getCode()))
			{
				return true;
			}
		}

		return false;
	}
}
