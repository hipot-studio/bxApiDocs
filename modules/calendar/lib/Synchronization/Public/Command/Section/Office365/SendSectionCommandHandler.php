<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Public\Command\Section\Office365;

use Bitrix\Calendar\Sync\Office365\Factory;
use Bitrix\Calendar\Synchronization\Internal\Exception\Messenger\MessageSendingException;
use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Calendar\Synchronization\Internal\Service\Export\SectionEventsExporter;
use Bitrix\Calendar\Synchronization\Internal\Service\Messenger\Message\Push\SubscribeSectionMessage;
use Bitrix\Calendar\Synchronization\Internal\Service\Messenger\Queue;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Office365\Office365SectionSynchronizer;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;
use Bitrix\Main\Messenger\Internals\Exception\Broker\SendFailedException;
use Bitrix\Main\Repository\Exception\PersistenceException;

class SendSectionCommandHandler
{
	private Office365SectionSynchronizer $sectionSynchronizer;
	private SectionEventsExporter $sectionEventsExporter;

	public function __construct()
	{
		$this->sectionSynchronizer = ServiceLocator::getInstance()->get(Office365SectionSynchronizer::class);
		$this->sectionEventsExporter = ServiceLocator::getInstance()->get(SectionEventsExporter::class);
	}

	/**
	 * @throws ConfigurationException
	 * @throws MessageSendingException
	 * @throws PersistenceException
	 * @throws SendFailedException
	 * @throws SynchronizerException
	 */
	public function __invoke(SendSectionCommand $command): void
	{
		$sectionConnection = $this->sectionSynchronizer->sendSection($command->section);

		// Push subscription goes first: a failing catch-up export must not postpone it.
		if ($command->subscribeToPush)
		{
			$message = new SubscribeSectionMessage($command->section->getId());

			$message->send(Queue::Office365Push->value, [new ItemIdParam($command->section->getId())]);
		}

		if ($command->isSectionCreated && $sectionConnection !== null)
		{
			$this->sectionEventsExporter->exportUnsyncedEvents($sectionConnection, Factory::SERVICE_NAME);
		}
	}
}
