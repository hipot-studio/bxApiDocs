<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\Internal\Enum\ExternalLinkScenario;
use Bitrix\Disk\Internal\Repository\Interface\ExternalLinkRepositoryInterface;
use Bitrix\Disk\Internal\Service\Folder\MailAttachmentsFolderPredicate;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\SpecificFolder;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Throwable;

/**
 * The only writer of the mail attachments scenario: fields of a created link come from
 * {@see MailAttachmentLinkContract} and never from the caller.
 */
final class MailAttachmentExternalLinkService
{
	public const FEATURE = 'disk_manual_external_link';

	public const ERROR_FEATURE_NOT_AVAILABLE = 'FEATURE_NOT_AVAILABLE';
	public const ERROR_OBJECT_NOT_FOUND = 'OBJECT_NOT_FOUND';
	public const ERROR_OBJECT_OUT_OF_SCOPE = 'OBJECT_OUT_OF_SCOPE';
	public const ERROR_LINK_CREATION_FAILED = 'LINK_CREATION_FAILED';

	private const LOCK_NAME_PREFIX = 'disk_mail_attachment_external_link_';
	private const LOCK_TIMEOUT_SECONDS = 5;

	/**
	 * @param bool $creationAvailable answer of the tariff about {@see self::FEATURE}, read once for the
	 *   hit when the portal builds the service (`disk/.settings.php`)
	 */
	public function __construct(
		private readonly MailAttachmentsFolderPredicate $folderPredicate,
		private readonly ExternalLinkRepositoryInterface $linkRepository,
		private readonly ExternalLinkScenarioRegistry $scenarioRegistry,
		private readonly bool $creationAvailable,
	)
	{
	}

	public function isCreationAvailable(): bool
	{
		return $this->creationAvailable;
	}

	/**
	 * Creates the service link of a mail attachment object, returns the already existing one, or
	 * replaces the working manual links of the object with a service one.
	 *
	 * The link never expires unless $deathTime is passed. A bounded one expires once and is not
	 * restorable: a link re-created from the interface answers to the portal policy as a regular one.
	 * Access rights of the caller on the object are not checked here.
	 *
	 * @return Result data on success: ['externalLinkId' => int, 'url' => string]
	 */
	public function createLink(int $userId, int $objectId, ?DateTime $deathTime = null): Result
	{
		$result = new Result();

		if (!$this->isCreationAvailable())
		{
			return $result->addError(new Error(
				'Manual external links are not available on the current tariff',
				self::ERROR_FEATURE_NOT_AVAILABLE,
			));
		}

		$object = BaseObject::loadById($objectId);
		if (!($object instanceof BaseObject) || $object->isDeleted())
		{
			return $result->addError(new Error('Object is not found', self::ERROR_OBJECT_NOT_FOUND));
		}

		// A shortcut points outside of the tree, and the root of the tree holds every batch of the user,
		// the ones created after the publication included: neither is an attachment of a letter.
		if (
			$object->isLink()
			|| $this->isMailAttachmentsFolderItself($object)
			|| !$this->folderPredicate->isInsideMailAttachmentsTreeOfUser($objectId, $userId)
		)
		{
			return $result->addError(new Error(
				'Object is outside of the mail attachments folder tree of the user storage',
				self::ERROR_OBJECT_OUT_OF_SCOPE,
			));
		}

		// The check of the working links and the replacement have to see each other: two concurrent calls
		// would otherwise both insert a working link, while the interface sees and switches off only one.
		// A call which cannot enter the section answers with a retryable refusal.
		$connection = Application::getConnection();
		$lockName = self::LOCK_NAME_PREFIX . $objectId;
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT_SECONDS))
		{
			return $result->addError(new Error(
				message: 'Could not enter the link replacement section of the object',
				code: self::ERROR_LINK_CREATION_FAILED,
				customData: ['reason' => ['A concurrent call holds the lock of the object']],
			));
		}

		try
		{
			// The schema does not hold the object to a single working row, and the generic creation paths
			// know nothing of the lock: a duplicate of their race would stay open next to the service link
			// with settings of its own.
			$serviceLink = null;
			$obsoleteLinks = [];
			foreach ($this->linkRepository->getAllForUse($objectId) as $link)
			{
				if ($serviceLink === null && $link->isMailAttachmentLink())
				{
					$serviceLink = $link;
				}
				else
				{
					$obsoleteLinks[] = $link;
				}
			}

			if ($serviceLink !== null && $obsoleteLinks === [])
			{
				return $result->setData($this->buildLinkData($serviceLink));
			}

			return $this->replaceWorkingLinks(
				$result,
				$object,
				$userId,
				$objectId,
				$serviceLink,
				$obsoleteLinks,
				$deathTime,
			);
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function isMailAttachmentsFolderItself(BaseObject $object): bool
	{
		return $object->getCode() === SpecificFolder::CODE_FOR_MAIL_ATTACHMENTS;
	}

	/**
	 * The deletion of the old rows, the new row and the mark live in one transaction: a refused step
	 * must not eat the address the already sent letters carry.
	 */
	private function replaceWorkingLinks(
		Result $result,
		BaseObject $object,
		int $userId,
		int $objectId,
		?ExternalLink $serviceLink,
		array $obsoleteLinks,
		?DateTime $deathTime,
	): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			foreach ($obsoleteLinks as $obsoleteLink)
			{
				if (!$obsoleteLink->delete())
				{
					$this->rollback($connection);

					return $result->addError(new Error(
						'Could not replace the regular external link of the object',
						self::ERROR_LINK_CREATION_FAILED,
					));
				}
			}

			if ($serviceLink === null)
			{
				// The model reports the reason of a refused row only into the collection, and the caller gets
				// the same code for every reason, so the reason travels with the error.
				$creationErrors = new ErrorCollection();
				$serviceLink = $this->addLink($userId, $objectId, $deathTime, $creationErrors);
				if (!$serviceLink instanceof ExternalLink)
				{
					$this->rollback($connection);

					return $result->addError(new Error(
						message: 'Could not create external link',
						code: self::ERROR_LINK_CREATION_FAILED,
						customData: ['reason' => $this->describeErrors($creationErrors)],
					));
				}

				// A link re-created from the interface carries no lifetime, so only an indefinite link leaves
				// the mark behind. A bounded one takes away the mark of an earlier indefinite link, which
				// would otherwise revive the address as indefinite right after the expiration.
				if ($deathTime === null)
				{
					if (!$this->scenarioRegistry->mark($object, ExternalLinkScenario::MailAttachments))
					{
						$this->rollback($connection);

						return $result->addError(new Error(
							'Could not remember the service nature of the link',
							self::ERROR_LINK_CREATION_FAILED,
						));
					}
				}
				elseif (!$this->scenarioRegistry->unmark($object))
				{
					$this->rollback($connection);

					return $result->addError(new Error(
						'Could not take away the stale service mark of the object',
						self::ERROR_LINK_CREATION_FAILED,
					));
				}
			}
		}
		catch (Throwable $exception)
		{
			$this->rollback($connection);

			throw $exception;
		}

		// The core drops the transaction level before COMMIT, so a refused commit is nothing to roll back.
		$connection->commitTransaction();

		return $result->setData($this->buildLinkData($serviceLink));
	}

	/**
	 * A nested {@see Connection::rollbackTransaction()} rolls the savepoint back and then throws by
	 * contract of the core: the rollback of the own section is complete, so the exception stays here.
	 */
	private function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}
	}

	private function buildLinkData(ExternalLink $externalLink): array
	{
		return [
			'externalLinkId' => (int)$externalLink->getId(),
			'url' => $externalLink->generateUrl()->getUri(),
		];
	}

	private function addLink(
		int $userId,
		int $objectId,
		?DateTime $deathTime,
		ErrorCollection $errorCollection,
	): ?ExternalLink
	{
		$data = MailAttachmentLinkContract::applyTo(
			[
				'OBJECT_ID' => $objectId,
				'CREATED_BY' => $userId,
				'TYPE' => ExternalLink::TYPE_MANUAL,
			],
			$deathTime,
		);

		return ExternalLink::add($data, $errorCollection);
	}

	/**
	 * {@see \Bitrix\Disk\Internals\Model::add()} closes the collection with a generic error carrying no
	 * message of its own, so only the reported reasons are kept.
	 *
	 * @return string[]
	 */
	private function describeErrors(ErrorCollection $errorCollection): array
	{
		$messages = [];
		foreach ($errorCollection->toArray() as $error)
		{
			$message = $error->getMessage();
			if ($message !== '')
			{
				$messages[] = $message;
			}
		}

		return $messages;
	}
}
