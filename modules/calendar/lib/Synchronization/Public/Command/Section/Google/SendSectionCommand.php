<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Public\Command\Section\Google;

use Bitrix\Calendar\Core\Section\Section;
use Bitrix\Calendar\Synchronization\Internal\Exception\PushException;
use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\NotAuthorizedException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\Messenger\Internals\Exception\Broker\SendFailedException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Result;

class SendSectionCommand extends AbstractCommand
{
	/**
	 * @param bool $subscribeToPush Subscribe the section to vendor push notifications.
	 * @param bool $isSectionCreated Section is new for the vendor: enables the catch-up export of its
	 *     events. Off by default, so sending an already known section stays a plain send: only the
	 *     section receiver knows a creation from an update and turns the flag on.
	 */
	public function __construct(
		public readonly Section $section,
		public readonly bool $subscribeToPush = true,
		public readonly bool $isSectionCreated = false
	)
	{
	}

	/**
	 * @throws PushException
	 * @throws SynchronizerException
	 * @throws NotAuthorizedException
	 * @throws ConfigurationException
	 * @throws SendFailedException
	 * @throws PersistenceException
	 */
	protected function execute(): Result
	{
		(new SendSectionCommandHandler())($this);

		return new Result();
	}
}
