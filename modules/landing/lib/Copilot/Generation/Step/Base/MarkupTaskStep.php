<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Base;

use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;

/**
 * Task step of the markup scenario. Any failure is recorded into the generation state
 * before it stops the scenario: the runtime keeps only step statuses, so without this
 * record the intake surfaces have no error text to show.
 *
 * The record reaches the answers of the tools, so only the texts the scenario words itself
 * (GenerationException) are recorded as they are. Any other failure may carry SQL, file paths
 * or internal codes: it is written to the generation log and recorded as a neutral text.
 */
abstract class MarkupTaskStep extends TaskStep
{
	public const TECHNICAL_FAILURE_MESSAGE = 'An internal error of the portal stopped the markup build. '
		. 'Submit the package again; if the failure repeats, try later.';

	abstract protected function doExecute(): bool;

	public function execute(): bool
	{
		parent::execute();

		try
		{
			return $this->doExecute();
		}
		catch (GenerationException $exception)
		{
			(new MarkupSiteState($this->generation))->setLastError($exception->getMessage());

			throw $exception;
		}
		catch (\Throwable $exception)
		{
			$this->logUnexpectedFailure($exception);
			(new MarkupSiteState($this->generation))->setLastError(self::TECHNICAL_FAILURE_MESSAGE);

			throw new GenerationException(GenerationErrors::dataValidation, self::TECHNICAL_FAILURE_MESSAGE);
		}
	}

	protected function logUnexpectedFailure(\Throwable $exception): void
	{
		try
		{
			(new Log($this->generation->getId()))->add(
				'Markup step ' . (int)($this->stepId ?? 0) . ' of generation ' . (int)$this->generation->getId()
				. ' failed: ' . get_class($exception) . ': ' . $exception->getMessage()
				. ' at ' . $exception->getFile() . ':' . $exception->getLine()
			);
		}
		catch (\Throwable)
		{
			// the log loses the line, the failure is recorded anyway
		}
	}
}
