<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer;

use Bitrix\Landing\Transfer\Script\IScript;
use Bitrix\Main\Event;

/**
 * Manage common issues. Gives the job to the director etc.
 */
class Producer
{
	private Requisite\Context $context;
	private Director $director;
	private IScript $script;

	public function __construct(Event $event)
	{
		$this->context = Requisite\Factory::contextualizeEvent($event);
		$this->director = new Director($this->context);
		$this->script = (new Script\Factory())->writeScript($this->context);
	}

	public function make(): array
	{
		try
		{
			$this->director->make($this->script);

			return Requisite\Factory::returnalizeContext($this->context);
		}
		catch (TransferException $exception)
		{
			return $this->returnalizeError($exception);
		}
	}

	public function finishMake(): array
	{
		$this->director->setFinishRun();
		try
		{
			$this->director->make($this->script);

			return Requisite\Factory::returnalizeFinishContext($this->context);
		}
		catch (TransferException $exception)
		{
			return $this->returnalizeError($exception);
		}
	}

	private function returnalizeError(TransferException $exception): array
	{
		return [
			'ERROR_EXCEPTION' => $exception->getMessage(),
		];
	}

	// todo: now return, this or from script?
}
