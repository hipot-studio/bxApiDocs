<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Public\Command\Section\Google;

use Bitrix\Calendar\Internals\Container;
use Bitrix\Calendar\Sync\Google\Factory;
use Bitrix\Calendar\Synchronization\Internal\Exception\Messenger\MessageSendingException;
use Bitrix\Calendar\Synchronization\Internal\Exception\PushException;
use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Calendar\Synchronization\Internal\Exception\Vendor\NotAuthorizedException;
use Bitrix\Calendar\Synchronization\Internal\Service\Export\SectionEventsExporter;
use Bitrix\Calendar\Synchronization\Internal\Service\Messenger\Message\Push\SubscribeSectionMessage;
use Bitrix\Calendar\Synchronization\Internal\Service\Messenger\Queue;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\GoogleSectionSynchronizer;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;
use Bitrix\Main\Messenger\Internals\Exception\Broker\SendFailedException;
use Bitrix\Main\Repository\Exception\PersistenceException;

class SendSectionCommandHandler
{
	private GoogleSectionSynchronizer $sectionSynchronizer;
	private SectionEventsExporter $sectionEventsExporter;

	public function __construct()
	{
		$this->sectionSynchronizer = Container::getGoogleSectionSynchronizer();
		$this->sectionEventsExporter = ServiceLocator::getInstance()->get(SectionEventsExporter::class);
	}

	/**
	 * @throws ConfigurationException
	 * @throws MessageSendingException
	 * @throws NotAuthorizedException
	 * @throws PersistenceException
	 * @throws SendFailedException
	 * @throws SynchronizerException
	 * @throws PushException
	 */
	public function __invoke(SendSectionCommand $command): void
	{
		$sectionConnection = $this->sectionSynchronizer->sendSection($command->section);

		// Push subscription goes first: a failing catch-up export must not postpone it.
		if ($command->subscribeToPush)
		{
			$message = new SubscribeSectionMessage($command->section->getId());

			$message->send(Queue::GooglePush->value, [new ItemIdParam($command->section->getId())]);
		}

		if ($command->isSectionCreated && $sectionConnection !== null)
		{
			$this->sectionEventsExporter->exportUnsyncedEvents($sectionConnection, Factory::SERVICE_NAME);
		}
	}
}
