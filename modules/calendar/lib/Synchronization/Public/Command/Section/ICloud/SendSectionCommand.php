<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Public\Command\Section\ICloud;

use Bitrix\Calendar\Core\Section\Section;
use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Result;

class SendSectionCommand extends AbstractCommand
{
	/**
	 * @param bool $isSectionCreated Section is new for the vendor: enables the catch-up export of its
	 *     events. Off by default, so sending an already known section stays a plain send: only the
	 *     section receiver knows a creation from an update and turns the flag on.
	 */
	public function __construct(
		public readonly Section $section,
		public readonly bool $isSectionCreated = false
	)
	{
	}

	public function toArray(): array
	{
		return [
			'section' => $this->section->toArray(),
			'isSectionCreated' => $this->isSectionCreated,
		];
	}

	/**
	 * @throws SynchronizerException
	 * @throws ArgumentException
	 * @throws PersistenceException
	 */
	protected function execute(): Result
	{
		(new SendSectionCommandHandler())($this);

		return new Result();
	}
}
