<?php

namespace Bitrix\Disk\Rest\Service;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Service\ExternalLink\ExternalLinkAccessService;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\Public\Provider\ExternalLinkTariffProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\AccessException;
use Bitrix\Rest\RestException;
use Bitrix\Disk;

abstract class BaseObject extends Base
{
	protected ExternalLinkProvider $externalLinkProvider;
	protected ExternalLinkAccessService $externalLinkAccessService;
	protected ?ExternalLinkTariffProvider $externalLinkTariffProvider = null;

	public function __construct($methodName, array $params, $start, \CRestServer $restServer)
	{
		parent::__construct($methodName, $params, $start, $restServer);

		$this->externalLinkProvider = ServiceLocator::getInstance()->get(ExternalLinkProvider::class);
		$this->externalLinkAccessService = ServiceLocator::getInstance()->get(ExternalLinkAccessService::class);
		$this->externalLinkTariffProvider = ServiceLocator::getInstance()->get(ExternalLinkTariffProvider::class);
	}

	/**
	 * Returns work-object by id.
	 * @param int $id Id of object.
	 * @return Disk\File|Disk\Folder
	 */
	abstract protected function getWorkObjectById($id);

	/**
	 * Returns object by id.
	 * @param int $id Id of object.
	 * @return Disk\File|Disk\Folder
	 * @throws AccessException
	 */
	protected function get($id)
	{
		return $this->getByStorageRights($id);
	}

	/**
	 * Returns object by id, readable by the rights of its storage only.
	 *
	 * Methods giving more than the metadata and the content of an object stay on this check.
	 *
	 * @param int $id Id of object.
	 * @return Disk\File|Disk\Folder
	 * @throws AccessException
	 */
	protected function getByStorageRights($id)
	{
		$object = $this->getWorkObjectById($id);
		if (!$this->canReadByStorageRights($object))
		{
			throw new AccessException;
		}

		return $object;
	}

	protected function canReadByStorageRights(Disk\BaseObject $object): bool
	{
		$securityContext = $object->getStorage()->getCurrentUserSecurityContext();

		return $object->canRead($securityContext);
	}

	/**
	 * Renames object.
	 * @param int    $id      Id of object.
	 * @param string $newName New name for object.
	 * @return Disk\File|Disk\Folder|null
	 * @throws AccessException
	 */
	protected function rename($id, $newName)
	{
		$object = $this->getWorkObjectById($id);
		$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
		if(!$object->canRename($securityContext))
		{
			throw new AccessException;
		}
		if(!$object->rename($newName))
		{
			$this->errorCollection->add($object->getErrors());
			return null;
		}

		return $object;
	}

	/**
	 * Copies object to target folder.
	 * @param int $id             Id of object.
	 * @param int $targetFolderId Id of target folder.
	 * @return Disk\File|Disk\Folder|null
	 * @throws AccessException
	 * @throws RestException
	 */
	protected function copyTo($id, $targetFolderId)
	{
		$object = $this->getWorkObjectById($id);
		$targetFolder = $this->getFolderById($targetFolderId);

		$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
		$targetSecurityContext = $targetFolder->getStorage()->getCurrentUserSecurityContext();
		if(!$object->canRead($securityContext) || !$targetFolder->canAdd($targetSecurityContext))
		{
			throw new AccessException;
		}

		$newFile = $object->copyTo($targetFolder, $this->userId);
		if(!$newFile)
		{
			$this->errorCollection->add($object->getErrors());
			return null;
		}

		return $newFile;
	}

	/**
	 * Moves object to target folder.
	 * @param int $id             Id of object.
	 * @param int $targetFolderId Id of target folder.
	 * @return Disk\File|Disk\Folder|bool|null
	 * @throws AccessException
	 * @throws RestException
	 */
	protected function moveTo($id, $targetFolderId)
	{
		$object = $this->getWorkObjectById($id);
		$targetFolder = $this->getFolderById($targetFolderId);

		if($object->getStorageId() != $targetFolder->getStorageId())
		{
			$this->errorCollection->addOne(new Disk\Internals\Error\Error('Could not move object to another storage'));
			return false;
		}

		$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
		if(!$object->canMove($securityContext, $targetFolder))
		{
			throw new AccessException;
		}
		if(!$object->getParentId())
		{
			throw new RestException('Could not move root folder.');
		}
		if(!$object->moveTo($targetFolder, $this->userId))
		{
			$this->errorCollection->add($object->getErrors());
			return null;
		}

		return $object;
	}

	/**
	 * Marks deleted object (moves in trash).
	 * @param int $id Id of object.
	 * @return Disk\File|Disk\Folder|null
	 * @throws AccessException
	 * @throws RestException
	 */
	protected function markDeleted($id)
	{
		$object = $this->getWorkObjectById($id);
		$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
		if(!$object->canMarkDeleted($securityContext))
		{
			throw new AccessException;
		}
		if(!$object->getParentId())
		{
			throw new RestException('Could not delete root folder.');
		}
		if(!$object->markDeleted($this->userId))
		{
			$this->errorCollection->add($object->getErrors());
			return null;
		}

		return $object;
	}

	/**
	 * Restores object from trash.
	 * @param int $id Id of object.
	 * @return Disk\File|Disk\Folder|null
	 * @throws AccessException
	 */
	protected function restore($id)
	{
		$object = $this->getWorkObjectById($id);
		$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
		if(!$object->canRestore($securityContext))
		{
			throw new AccessException;
		}
		if(!$object->restore($this->userId))
		{
			$this->errorCollection->add($object->getErrors());
			return null;
		}
		// REST has no place for a warning beside the answer: the files the format policy kept in the
		// trash can make it an error, while the folder stays restored
		if ($object->getErrors())
		{
			$this->errorCollection->add($object->getErrors());

			return null;
		}

		return $object;
	}

	protected function listAllowedOperations($id, $userId)
	{
		$object = $this->get($id);

		$rightsManager = Driver::getInstance()->getRightsManager();
		$securityContext = $object->getStorage()->getCurrentUserSecurityContext();
		if (!$object->canChangeRights($securityContext))
		{
			throw new AccessException;
		}

		return array_values($rightsManager->getUserOperationsByObject($object->getId(), $userId));
	}

	/**
	 * Returns new or existent external link for current user on the file or folder.
	 * @param int $id Id of file or folder.
	 * @return null|string
	 * @throws AccessException
	 */
	protected function getExternalLink($id)
	{
		$object = $this->getByStorageRights($id);
		if (
			!$this->externalLinkAccessService->isExternalLinkEnabledOnPortal()
			|| !$this->externalLinkAccessService->canManageExternalLink($object)
		)
		{
			throw new AccessException;
		}

		$extModel = $this->externalLinkProvider->getForUse($object->getRealObjectId());

		if(!$extModel)
		{
			if (!$this->getExternalLinkTariffProvider()->isManualLinkCreationAllowed($object))
			{
				throw new AccessException;
			}

			$extModel = $object->getRealObject()->addExternalLink(array(
				'CREATED_BY' => $this->userId,
				'TYPE' => ExternalLinkTable::TYPE_MANUAL,
			));
		}
		if(!$extModel)
		{
			$this->errorCollection->add($object->getErrors());

			return null;
		}

		return Driver::getInstance()->getUrlManager()->getCopyablePublicExternalLinkForConsumer(
			$extModel,
			Disk\UrlManager::PUBLIC_LINK_CONSUMER_UNMARKED_COPY,
			$object,
		);
	}

	private function getExternalLinkTariffProvider(): ExternalLinkTariffProvider
	{
		// Descendants may be built without the parent constructor.
		$this->externalLinkTariffProvider ??= ServiceLocator::getInstance()
			->get(ExternalLinkTariffProvider::class)
		;

		return $this->externalLinkTariffProvider;
	}

	/**
	 * Returns file by id.
	 * @param int $id Id of object.
	 * @return Disk\File
	 * @throws RestException
	 */
	protected function getFileById($id)
	{
		$folder = Disk\File::getById($id, array('STORAGE'));
		if(!$folder)
		{
			throw new RestException("Could not find entity with id '{$id}'.", RestException::ERROR_NOT_FOUND);
		}

		return $folder;
	}

	/**
	 * Returns folder by id.
	 * @param int $id Id of object.
	 * @return Disk\Folder
	 * @throws RestException
	 */
	protected function getFolderById($id)
	{
		$folder = Disk\Folder::getById($id, array('STORAGE'));
		if(!$folder)
		{
			throw new RestException("Could not find entity with id '{$id}'.", RestException::ERROR_NOT_FOUND);
		}

		return $folder;
	}

}