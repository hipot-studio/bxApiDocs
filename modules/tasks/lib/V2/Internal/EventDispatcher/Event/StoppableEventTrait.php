<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventDispatcher\Event;

trait StoppableEventTrait
{
	private bool $isPropagationStopped = false;

	public function isPropagationStopped(): bool
	{
		return $this->isPropagationStopped;
	}
	
	public function stopPropagation(): void
	{
		$this->isPropagationStopped = true;
	}
}
