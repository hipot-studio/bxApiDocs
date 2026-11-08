<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Public\Command\Section\ICloud;

use Bitrix\Calendar\Internals\Container;
use Bitrix\Calendar\Sync\Icloud\Factory;
use Bitrix\Calendar\Synchronization\Internal\Exception\Messenger\MessageSendingException;
use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Calendar\Synchronization\Internal\Service\Export\SectionEventsExporter;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\ICloud\ICloudSectionSynchronizer;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Repository\Exception\PersistenceException;

class SendSectionCommandHandler
{
	private ICloudSectionSynchronizer $sectionSynchronizer;
	private SectionEventsExporter $sectionEventsExporter;

	public function __construct()
	{
		$this->sectionSynchronizer = Container::getICloudSectionSynchronizer();
		$this->sectionEventsExporter = ServiceLocator::getInstance()->get(SectionEventsExporter::class);
	}

	/**
	 * @throws ArgumentException
	 * @throws MessageSendingException
	 * @throws SynchronizerException
	 * @throws PersistenceException
	 */
	public function __invoke(SendSectionCommand $command): void
	{
		$sectionConnection = $this->sectionSynchronizer->sendSection($command->section);
		if ($command->isSectionCreated && $sectionConnection !== null)
		{
			$this->sectionEventsExporter->exportUnsyncedEvents($sectionConnection, Factory::SERVICE_NAME);
		}
	}
}
