<?php

namespace Bitrix\Disk\Rest\Service;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ExternalLinkTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Rest\Externalizer;
use Bitrix\Disk\Rest\RestManager;
use Bitrix\Disk\Rest\Service\Search;
use Bitrix\Disk\Search\StorageFileFinder;
use Bitrix\Disk\Search\StorageFileFinderOptions;
use Bitrix\Rest\AccessException;
use Bitrix\Rest\RestException;
use Bitrix\Disk\Rest\Entity;
use Bitrix\Disk;

final class File extends BaseObject
{
	const VERSION_ON_PAGE = 50;

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
		$file = $this->get($id);

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
		if(!$file->canRestore($securityContext))
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

		$fileData = $file->getFile();
		if(!$fileData)
		{
			throw new RestException('Could not get content of file');
		}
		\CFile::viewByUser($fileData, array('force_download' => true, 'cache_time' => 0, 'attachment_name' => $file->getName()));
	}
}
