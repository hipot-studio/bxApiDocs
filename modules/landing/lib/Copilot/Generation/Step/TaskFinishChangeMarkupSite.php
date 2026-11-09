<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Markup\Dev\FailureInjection;

/**
 * Final step of the markup page edit. An edit has no chat binding to pin, so the step finishes as the common
 * one does; it only lets the dev failure injection stop the edit on its last step, as on the creation.
 */
class TaskFinishChangeMarkupSite extends Finish
{
	public function execute(): bool
	{
		$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

		return parent::execute();
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}
}
