<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Markup\Dev\FailureInjection;
use Bitrix\Landing\Markup\MarkupCreationOutcome;
use Bitrix\Landing\Markup\UploadBuffer;

/**
 * Final step of the markup site creation: the chat is pinned to the created site and the consumed buffer
 * is dropped. The scenario does both itself, so the chat is pinned whoever has run the last step - the
 * submission or the retry agent - and whether anyone asks for the status or not.
 *
 * A repeated run is safe: the chat already pinned to this site stays pinned, the dropped buffer is gone.
 */
class TaskFinishCreateMarkupSite extends Finish
{
	private const FINALIZE_FAILED_MESSAGE = 'The chat of the created markup site could not be pinned to it.';

	public function execute(): bool
	{
		$this->createFailureInjection()->raiseOnStep($this->generation, (int)($this->stepId ?? 0));

		if (!parent::execute())
		{
			return false;
		}

		if (!$this->createCreationOutcome()->finalize($this->generation))
		{
			(new MarkupSiteState($this->generation))->setLastError(self::FINALIZE_FAILED_MESSAGE);

			throw new GenerationException(GenerationErrors::dataValidation, self::FINALIZE_FAILED_MESSAGE);
		}

		$uploadId = (new MarkupSiteState($this->generation))->getUploadId();
		if ($uploadId !== '')
		{
			$this->getUploadBuffer()->drop($uploadId, $this->generation->getAuthorId());
		}

		return true;
	}

	protected function createCreationOutcome(): MarkupCreationOutcome
	{
		return new MarkupCreationOutcome();
	}

	protected function createFailureInjection(): FailureInjection
	{
		return new FailureInjection();
	}

	protected function getUploadBuffer(): UploadBuffer
	{
		return new UploadBuffer();
	}
}
