<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Public\Command\Push\Google;

use Bitrix\Calendar\Synchronization\Internal\Entity\SectionConnection;
use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Result;

class SubscribeSectionCommand extends AbstractCommand
{
	public function __construct(public readonly SectionConnection $sectionConnection)
	{
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectException
	 * @throws PersistenceException
	 * @throws SynchronizerException
	 */
	protected function execute(): Result
	{
		(new SubscribeSectionCommandHandler())($this);

		return new Result();
	}
}
