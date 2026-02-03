<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command;

use Bitrix\Disk\Document\OnlyOffice\Clients\CommandService\CommandServiceClientFactory;
use Bitrix\Disk\Document\OnlyOffice\Clients\CommandService\CommandServiceClientInterface;
use Bitrix\Disk\Internal\Entity\DocumentRestrictionLog;
use Bitrix\Disk\Internal\Entity\DocumentSession;
use Bitrix\Disk\Internal\Repository\Interface\DocumentRestrictionLogRepositoryInterface;
use Bitrix\Disk\Internal\Repository\Interface\DocumentSessionRepositoryInterface;
use Bitrix\Main\Application;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

class DropOnlyOfficeSessionsAfterSwitchCommandHandler
{
	protected DocumentRestrictionLogRepositoryInterface $documentRestrictionLogRepository;
	protected DocumentSessionRepositoryInterface $documentSessionRepository;
	protected CommandServiceClientInterface $commandServiceClient;

	/**
	 * @throws ConfigurationException
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 * @throws NotFoundExceptionInterface
	 */
	public function __construct()
	{
		$this->documentRestrictionLogRepository =
			ServiceLocator
				::getInstance()
				->get(DocumentRestrictionLogRepositoryInterface::class)
		;

		$this->documentSessionRepository =
			ServiceLocator
				::getInstance()
				->get(DocumentSessionRepositoryInterface::class)
		;

		$this->commandServiceClient = CommandServiceClientFactory::createCommandServiceClient();
	}

	/**
	 * @param DropOnlyOfficeSessionsAfterSwitchCommand $command
	 * @return ErrorCollection|null
	 * @throws Throwable
	 * @throws SqlQueryException
	 * @throws PersistenceException
	 */
	public function __invoke(DropOnlyOfficeSessionsAfterSwitchCommand $command): ?ErrorCollection
	{
		$sessions = $this->documentSessionRepository->getOnlyOfficeForDrop($command->switchedAt);
		$db = Application::getConnection();

		foreach ($sessions as $session)
		{
			// drop on the remote server
			/** @var DocumentSession $session */
			$dropResult = $this->commandServiceClient->drop(
				documentKey: $session->getExternalHash(),
				userIds: [$session->getUserId()],
			);

			if (!$dropResult->isSuccess())
			{
				// log here, because it's not logged at a lower level
				$command->logger?->error(
					message: $dropResult->getError()?->getMessage() ?? 'undefined',
				);

				return $dropResult->getErrorCollection();
			}

			// delete from storage
			try
			{
				$db->startTransaction();

				// first retrieve from storage by hash,
				// because we can't delete directly by hash using ORM
				$documentRestrictionLog =
					$this
						->documentRestrictionLogRepository
						->getByHash($session->getExternalHash())
				;

				if ($documentRestrictionLog instanceof DocumentRestrictionLog)
				{
					$this->documentRestrictionLogRepository->delete($documentRestrictionLog->getId());
				}

				$this->documentSessionRepository->delete($session->getId());

				$db->commitTransaction();
			}
			catch (Throwable $exception)
			{
				$db->rollbackTransaction();

				throw $exception;
			}
		}

		return null;
	}
}