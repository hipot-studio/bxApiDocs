<?php

namespace Bitrix\Disk\Rest\Service;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Entity\Audit\AuditChannel;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContextStack;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Rest\Externalizer;
use Bitrix\Disk\Rest\RestManager;
use Bitrix\Disk\Rest\Service\Search;
use Bitrix\Disk\Search\StorageFileFinder;
use Bitrix\Disk\Search\StorageFileFinderOptions;
use Bitrix\Main\SystemException;
use Bitrix\Rest\AccessException;
use Bitrix\Rest\RestException;
use Bitrix\Disk\Rest\Entity;
use Bitrix\Disk;

/** Not final: the unit suite replaces the object lookup and the attachment lookup by subclassing. */
class File extends BaseObject
{
	const VERSION_ON_PAGE = 50;

	/** Every attachment asks a foreign module about the rights of the user, so their number is capped. */
	private const ATTACHED_OBJECT_LIMIT = 20;

	/** @var array<int, Disk\AttachedObject[]> */
	private array $attachedObjects = [];

	/** @var array<int, true> */
	private array $readByAttachedObject = [];

	/**
	 * Returns field descriptions (type, possibility to usage in filter, in render).
	 * @return array
	 */
	protected function getFields()
	{
		$storage = new Entity\File;
		return $storage->getFields();
	}

	/**
	 * Returns file by id.
	 * @param int $id Id of file.
	 * @return Disk\File
	 * @throws RestException
	 */
	protected function getWorkObjectById($id)
	{
		return $this->getFileById($id);
	}

	/**
	 * Returns file by id, falling back to the rights of an attachment of the file.
	 *
	 * @param int $id Id of file.
	 * @return Disk\File
	 * @throws AccessException
	 * @throws RestException
	 */
	protected function get($id)
	{
		/** @var Disk\File $file */
		$file = $this->getWorkObjectById($id);
		if ($this->canReadByStorageRights($file))
		{
			return $file;
		}

		$attachedObject = $this->findAllowedAttachedObject(
			$file,
			fn(Disk\AttachedObject $attachedObject): bool => $attachedObject->canRead($this->userId),
		);
		if (!$attachedObject)
		{
			throw new AccessException;
		}

		$this->readByAttachedObject[(int)$file->getId()] = true;

		return $file;
	}

	/** A file opened by an attachment tells nothing about the storage it lies in, its path included. */
	public function shouldBuildDetailUrl(Disk\BaseObject $object): bool
	{
		return !$this->isReadByAttachedObject((int)$object->getId());
	}

	protected function isReadByAttachedObject(int $fileId): bool
	{
		return isset($this->readByAttachedObject[$fileId]);
	}

	protected function copyTo($id, $targetFolderId)
	{
		return AuditOperationContextStack::runWithContext(
			AuditOperationContext::create(
				actionKind: AuditOperationContext::ACTION_EXPLICIT_COPY,
				channel: AuditChannel::API,
				actionContext: $this->createAuditRestActionContext(),
				selectedTargetIds: [(string)$id],
			),
			fn() => parent::copyTo($id, $targetFolderId),
		);
	}

	protected function moveTo($id, $targetFolderId)
	{
		$object = $this->getWorkObjectById($id);
		$targetFolder = $this->getFolderById($targetFolderId);

		return AuditOperationContextStack::runWithContext(
			AuditOperationContext::create(
				actionKind: $object->getStorageId() == $targetFolder->getStorageId()
					? AuditOperationContext::ACTION_EXPLICIT_MOVE
					: AuditOperationContext::ACTION_CROSS_STORAGE_MOVE,
				channel: AuditChannel::API,
				actionContext: $this->createAuditRestActionContext(),
				selectedTargetIds: [(string)$id],
			),
			fn() => parent::moveTo($id, $targetFolderId),
		);
	}

	protected function search($QUERY, $TYPE = null, $FILTER = null)
	{
		$requestResult = Search\SearchRequest::create($QUERY, $TYPE, $FILTER, (int)$this->start);
		if (!$requestResult->isSuccess())
		{
			$this->errorCollection->add($requestResult->getErrors());

			return null;
		}

		/** @var Search\SearchRequest $request */
		$request = $requestResult->getData()['request'];
		$scopeResult = (new Search\SearchScopeResolver($this->userId))->resolve($request);
		if (!$scopeResult->isSuccess())
		{
			$this->errorCollection->add($scopeResult->getErrors());

			return null;
		}

		/** @var Search\SearchScope $scope */
		$scope = $scopeResult->getData()['scope'];
		$offset = $request->getOffset();
		$objectTypes = match ($scope->getType())
		{
			Search\SearchType::File => [ObjectTable::TYPE_FILE],
			Search\SearchType::Folder => [ObjectTable::TYPE_FOLDER],
			Search\SearchType::All => [ObjectTable::TYPE_FILE, ObjectTable::TYPE_FOLDER],
		};

		$options = new StorageFileFinderOptions(
			limit: RestManager::LIST_LIMIT + 1,
			offset: $offset,
			objectTypes: $objectTypes,
			storageId: $scope->getStorageId(),
			folderId: $scope->getFolderId(),
			proxyTypes: null,
			folderExcludedProxyType: \Bitrix\Im\Disk\ProxyType\Im::class,
			typeFileValues: $request->getFileTypeValues(),
		);
		$objects = (new StorageFileFinder($this->userId, options: $options))->findModelsByText($request->getQuery());
		$hasNextPage = count($objects) > RestManager::LIST_LIMIT;
		if ($hasNextPage)
		{
			array_pop($objects);
		}

		$linkPolicy = new Search\SearchResultLinkPolicy();
		Disk\CrumbStorage::getInstance()->preloadByObjects(array_filter(
			$objects,
			[$linkPolicy, 'shouldBuildDetailUrl'],
		));
		$externalizer = new Externalizer($this, $this->restServer, [$linkPolicy, 'shouldBuildDetailUrl']);
		$result = (new Search\SearchResultBuilder($linkPolicy))->build(
			$objects,
			static fn(Disk\BaseObject $object): array => $externalizer->getExternalData($object),
		);

		$nextOffset = $hasNextPage ? $request->getNextOffset(RestManager::LIST_LIMIT) : null;
		if ($nextOffset !== null)
		{
			$result['next'] = $nextOffset;
		}

		return $result;
	}

	/**
	 * Deletes file by id.
	 * @param int $id Id of file.
	 * @return bool
	 * @throws RestException
	 */
	protected function delete($id)
	{
		$file = $this->getFileById($id);
		$securityContext = $file->getStorage()->getCurrentUserSecurityContext();
		if(!$file->canDelete($securityContext))
		{
			throw new AccessException;
		}
		if(!$file->delete($this->userId))
		{
			$this->errorCollection->add($file->getErrors());
			return false;
		}

		return true;
	}

	/**
	 * Creates new version of file.
	 * @param int $id Id of file.
	 * @param string|array $fileContent File content. General format in REST.
	 * @return Disk\Version|null
	 * @throws AccessException
	 * @throws RestException
	 */
	protected function uploadVersion($id, $fileContent)
	{
		$file = $this->getFileById($id);
		$securityContext = $file->getStorage()->getCurrentUserSecurityContext();
		if(!$file->canUpdate($securityContext))
		{
			throw new AccessException;
		}
		$fileData = \CRestUtil::saveFile($fileContent);
		if(!$fileData)
		{
			throw new RestException('Could not save file.');
		}
		$newFile = $file->uploadVersion($fileData, $this->userId);
		if(!$newFile)
		{
			$this->errorCollection->add($file->getErrors());
			return null;
		}

		return $file;
	}

	/**
	 * Returns list of versions file.
	 *
	 * @param int $id Id of file.
	 * @param array $filter Filter.
	 *
	 * @return Disk\Version[]|null
	 */
	protected function getVersions($id, array $filter = array())
	{
		/** @var Disk\File $file */
		$file = $this->getByStorageRights($id);

		$internalizer = new Disk\Rest\Internalizer(new Entity\Version, $this);

		$navData = Disk\Rest\RestManager::getNavData($this->start);
		$filter = $internalizer->cleanFilter($filter);
		$filter['OBJECT_ID'] = $file->getId();
		$parameters = array_merge(array(
			'filter' => $filter,
			'order' => array(
				'CREATE_TIME' => 'DESC',
			),
			'count_total' => true,
		), $navData);

		$versions = array();
		$versionRows = Disk\Internals\VersionTable::getList($parameters);
		foreach ($versionRows as $versionRow)
		{
			$versions[] = Disk\Version::buildFromArray($versionRow);
		}

		return Disk\Rest\RestManager::setNavData(
			$versions,
			array(
				"count" => $versionRows->getCount(),
				"offset" => $navData['offset'],
			)
		);
	}

	/**
	 * Restores file from the version.
	 *
	 * @param int $id Id of file.
	 * @param int $versionId Id of version.
	 *
	 * @return Disk\File|null
	 * @throws AccessException
	 * @throws RestException
	 */
	protected function restoreFromVersion($id, $versionId)
	{
		/** @var Disk\File $file */
		$file = $this->get($id);

		$securityContext = $file->getStorage()->getCurrentUserSecurityContext();
		if(!$file->canManageVersions($securityContext))
		{
			throw new AccessException;
		}

		$version = Disk\Version::getById($versionId);
		if (!$version)
		{
			throw new RestException("Could not find entity with id '{$versionId}'.", RestException::ERROR_NOT_FOUND);
		}

		if (!$file->restoreFromVersion($version, $this->userId))
		{
			$this->errorCollection->add($file->getErrors());

			return null;
		}

		return $file;
	}

	/**
	 * Sends file content.
	 *
	 * The method is invoked by \CRestUtil::METHOD_DOWNLOAD.
	 *
	 * @param int $id Id of file.
	 * @throws AccessException
	 * @throws RestException
	 * @return void
	 */
	protected function download($id)
	{
		/** @var Disk\File $file */
		$file = $this->get($id);

		$this->assertCanDownloadFile($file);

		$fileData = $file->getFile();
		if(!$fileData)
		{
			throw new RestException('Could not get content of file');
		}
		\CFile::viewByUser($fileData, array('force_download' => true, 'cache_time' => 0, 'attachment_name' => $file->getName()));
	}

	/** @throws AccessException */
	protected function assertCanDownloadFile(Disk\File $file): void
	{
		if ($this->canDownloadByStorageRights($file))
		{
			return;
		}

		$attachedObject = $this->findAllowedAttachedObject(
			$file,
			fn(Disk\AttachedObject $attachedObject): bool => $attachedObject->canDownload($this->userId),
		);
		if (!$attachedObject)
		{
			throw new AccessException;
		}
	}

	/**
	 * An attachment of a module which is gone answers with an exception, and the next one still can grant.
	 *
	 * @param callable(Disk\AttachedObject): bool $isAllowed
	 */
	private function findAllowedAttachedObject(Disk\File $file, callable $isAllowed): ?Disk\AttachedObject
	{
		foreach ($this->loadAttachedObjects($file) as $attachedObject)
		{
			try
			{
				if ($isAllowed($attachedObject))
				{
					return $attachedObject;
				}
			}
			catch (SystemException)
			{
			}
		}

		return null;
	}

	/**
	 * @return Disk\AttachedObject[]
	 */
	protected function loadAttachedObjects(Disk\File $file): array
	{
		return $this->attachedObjects[(int)$file->getId()] ??= $file->getAttachedObjects([
			'order' => [
				'ID' => 'DESC',
			],
			'limit' => self::ATTACHED_OBJECT_LIMIT,
			'with' => ['OBJECT'],
		]);
	}
}
