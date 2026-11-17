<?php


namespace Bitrix\Disk\Document;

use Bitrix\Disk\Analytics\DiskAnalytics;
use Bitrix\Disk\Analytics\Enum\DocumentHandlerType;
use Bitrix\Disk\Document\Flipchart\BoardService;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Integration\Collab\CollabDocumentCreationService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\Internals;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Public\Service\Restrictions\WriteErrorTransportMap;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Disk\User;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

class LocalDocumentController extends Internals\Controller
{
	const CODE = 'l';

	const ERROR_BAD_RIGHTS                              = 'DISK_LOCAL_DOC_CON_22002';
	const ERROR_COULD_NOT_FIND_FILE                     = 'DISK_LOCAL_DOC_CON_22005';
	const ERROR_COULD_NOT_FIND_VERSION                  = 'DISK_LOCAL_DOC_CON_22006';
	const ERROR_COULD_NOT_SAVE_FILE                     = 'DISK_LOCAL_DOC_CON_22008';
	const ERROR_COULD_NOT_ADD_VERSION                   = 'DISK_LOCAL_DOC_CON_22009';
	const ERROR_COULD_NOT_FIND_STORAGE                  = 'DISK_LOCAL_DOC_CON_22012';
	const ERROR_COULD_NOT_FIND_FOLDER_FOR_CREATED_FILES = 'DISK_LOCAL_DOC_CON_22013';
	const ERROR_COULD_NOT_CREATE_FILE                   = 'DISK_LOCAL_DOC_CON_22014';
	const ERROR_COULD_NOT_FIND_FOLDER                   = 'DISK_LOCAL_DOC_CON_22015';

	const STATUS_NOT_FOUND = 'not_found';

	protected UnifiedLinkAccessService $unifiedLinkAccessService;
	protected UnifiedLinkUrl $unifiedLinkUrl;
	/** @var  File */
	protected $file;
	/** @var int */
	protected $fileId;
	/** @var  Version */
	protected $version;
	/** @var int */
	protected $versionId;

	public function __construct()
	{
		parent::__construct();

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->unifiedLinkUrl = $serviceLocator->get(UnifiedLinkUrl::class);
	}

	public static function isLocalService($serviceName)
	{
		return mb_strtolower($serviceName) === self::CODE;
	}

	public static function getCode()
	{
		return self::CODE;
	}

	public static function getName()
	{
		return Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_NAME_3_WORK_WITH');
	}

	protected function listActions()
	{
		return array(
			'publishBlank' => array(
				'method' => array('POST', 'GET'),
				'name' => 'publishBlank',
				'check_csrf_token' => false,
			),
			'saveBlank' => array(
				'method' => array('POST'),
				'name' => 'commit',
			),
			'commit' => array(
				'method' => array('POST'),
				'name' => 'commit',
			),
			'show' => 'download',
			'publish' => 'download',
			'download',
		);
	}

	protected function isActionWithExistsFile()
	{
		return mb_strtolower($this->realActionName) != 'publishblank';
	}

	protected function prepareParams()
	{
		if(!$this->isActionWithExistsFile())
		{
			return true;
		}

		if(!$this->checkRequiredInputParams($_REQUEST, array('objectId')))
		{
			return false;
		}
		$this->fileId = (int)$_REQUEST['objectId'];
		if(!empty($_REQUEST['versionId']))
		{
			$this->versionId = (int)$_REQUEST['versionId'];
		}

		return true;
	}

	protected function processBeforeAction($actionName)
	{
		if($this->isActionWithExistsFile())
		{
			$this->initializeData();
			$this->checkReadPermissions();
		}

		return true;
	}

	protected function checkReadPermissions()
	{
		$securityContext = $this->file->getStorage()->getCurrentUserSecurityContext(
			$this->shouldCheckUnifiedLinkForFile(),
		);

		if(!$this->file->canRead($securityContext))
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_BAD_RIGHTS'), self::ERROR_BAD_RIGHTS)));
			$this->sendJsonErrorResponse();
		}
	}

	protected function shouldCheckUnifiedLinkForFile(): bool
	{
		$uls = $this->unifiedLinkUrl->getUlsFromRequest($this->request);

		return
			is_string($uls)
			&& $this->unifiedLinkAccessService->validateUlsForObjectId(
				objectId: $this->file->getId(),
				uls: $uls,
			)
		;
	}

	protected function checkUpdatePermissions()
	{
		$securityContext = $this->file->getStorage()->getCurrentUserSecurityContext(
			$this->shouldCheckUnifiedLinkForFile(),
		);

		if(!$this->file->canUpdate($securityContext))
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_BAD_RIGHTS'), self::ERROR_BAD_RIGHTS)));
			$this->sendJsonErrorResponse();
		}
	}

	/**
	 * @return boolean
	 */
	public function isSpecificVersion()
	{
		return (bool)$this->versionId;
	}

	protected function initializeData()
	{
		if($this->isSpecificVersion())
		{
			$this->initializeVersion($this->versionId);
		}
		else
		{
			$this->initializeFile($this->fileId);
		}
	}

	protected function initializeFile($fileId)
	{
		$this->file = File::loadById($fileId, array('STORAGE'));
		if(!$this->file)
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_COULD_NOT_FIND_FILE'), self::ERROR_COULD_NOT_FIND_FILE)));
			$this->sendJsonResponse(array('status' => self::STATUS_NOT_FOUND));
		}
	}

	protected function initializeVersion($versionId)
	{
		$this->version = Version::loadById($versionId, array('OBJECT.STORAGE'));
		if(!$this->version)
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_COULD_NOT_FIND_FILE'), self::ERROR_COULD_NOT_FIND_FILE)));
			$this->sendJsonResponse(array('status' => self::STATUS_NOT_FOUND));
		}
		$this->file = $this->version->getObject();
		if(!$this->file)
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_COULD_NOT_FIND_VERSION'), self::ERROR_COULD_NOT_FIND_VERSION)));
			$this->sendJsonResponse(array('status' => self::STATUS_NOT_FOUND));
		}
	}

	protected function processActionDownload()
	{
		if ($this->isSpecificVersion())
		{
			$response = Response\BFile::createByFileId($this->version->getFileId(), $this->version->getName());
		}
		else
		{
			$response = Response\BFile::createByFileId($this->file->getFileId(), $this->file->getName());
		}

		Application::getInstance()->end(0, $response);
	}

	protected function processActionCommit()
	{
		$this->checkRequiredFilesParams(array('file'));
		if($this->errorCollection->hasErrors())
		{
			$this->sendJsonErrorResponse();
		}
		$this->checkUpdatePermissions();

		//todo fix Cherezov. Ban encoding 1251
		$fileArray = $this->request->getFile('file');
		$fileArray['name'] = $this->file->getName();
		$userId = $this->getUser()->getId();
		if(!$this->file->uploadVersion($fileArray, $userId))
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_COULD_NOT_ADD_VERSION'), self::ERROR_COULD_NOT_ADD_VERSION)));
			$this->sendJsonErrorResponse();
		}

		Driver::getInstance()->sendEvent($userId, 'live', array(
			'objectId' => $this->file->getId(),
			'action' => 'commit',
		));

		$this->sendJsonSuccessResponse();
	}

	protected function processActionPublishBlank($type)
	{
		$fileData = null;
		$targetFolderId = (int)$this->request->getPost('targetFolderId');
		$collabId = (int)$this->request->getPost('collabId');
		$user = User::loadById($this->getUser()->getId());
		$collabDocumentCreationService = ServiceLocator::getInstance()->get(CollabDocumentCreationService::class);

		if($type !== 'board')
		{
			$fileData = new BlankFileData($type);
		}

		$calledFromDisk = false;
		if ($targetFolderId > 0)
		{
			$folder = Folder::loadById($targetFolderId, array('STORAGE'));
			if (!$folder)
			{
				$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_COULD_NOT_FIND_FOLDER'), self::ERROR_COULD_NOT_FIND_FOLDER)));
				$this->sendJsonErrorResponse();
			}
			$calledFromDisk = true;
		}
		elseif ($collabId > 0)
		{
			$folder = $collabDocumentCreationService->getCreatedFilesFolderByCollabId($collabId, $user->getId());
			if ($folder === null)
			{
				$this->errorCollection->add(array(new Error('There is no such collab or user has no access to it', self::ERROR_COULD_NOT_CREATE_FILE)));
				$this->sendJsonErrorResponse();
			}
		}
		else
		{
			$userStorage = Driver::getInstance()->getStorageByUserId($user->getId());
			if (!$userStorage)
			{
				$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_COULD_NOT_FIND_STORAGE'), self::ERROR_COULD_NOT_FIND_STORAGE)));
				$this->sendJsonErrorResponse();
			}
			$folder = $userStorage->getFolderForCreatedFiles();
		}


		if(!$folder)
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_COULD_NOT_FIND_FOLDER_FOR_CREATED_FILES'), self::ERROR_COULD_NOT_FIND_FOLDER_FOR_CREATED_FILES)));
			$this->sendJsonErrorResponse();
		}

		// TODO: remove false when frontend sends $collabId
		if (false && $user->isCollaber())
		{
			if ($calledFromDisk && !$collabDocumentCreationService->isFolderInCollabStorage($folder))
			{
				$this->errorCollection->add(array(new Error('Wrong target folder for collab user', self::ERROR_COULD_NOT_FIND_STORAGE)));
				$this->sendJsonErrorResponse();
			}

			if (!$calledFromDisk && $collabId < 1)
			{
				$this->errorCollection->add(array(new Error('No collab ID for collab user', self::ERROR_COULD_NOT_FIND_STORAGE)));
				$this->sendJsonErrorResponse();
			}
		}

		$storage = $folder->getStorage();
		$uls = $this->unifiedLinkUrl->getUlsFromRequest($this->request);

		$shouldCheckUnifiedLink =
			is_string($uls)
			&& $this->unifiedLinkAccessService->validateUlsForObjectId(
				objectId: $folder->getId(),
				uls: $uls,
			)
		;

		if(!$folder->canAdd($storage->getCurrentUserSecurityContext($shouldCheckUnifiedLink)))
		{
			$this->errorCollection->add(array(new Error(Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_BAD_RIGHTS'), self::ERROR_BAD_RIGHTS)));
			$this->sendJsonErrorResponse();
		}

		if ($type === 'board')
		{
			$createBoardResult = BoardService::createNewDocument($user, $folder);
			$newFile = null;
			if ($createBoardResult->isSuccess())
			{
				$newFile = $createBoardResult->getData()['file'];
			}
			else
			{
				$this->addErrorsOfRefusedCreation($createBoardResult->getErrors());
				$this->sendJsonErrorResponse();
			}
		}
		else
		{
			$newFile = $this->createBlankFile($folder, $fileData, $type, (int)$this->getUser()->getId());
		}


		if(!$newFile)
		{
			$this->addErrorsOfRefusedCreation($folder->getErrors());
			$this->sendJsonErrorResponse();
		}

		Application::getInstance()->addBackgroundJob(function () use ($newFile, $type) {
			DiskAnalytics::sendCreationFileEvent($newFile, $type === 'board' ? DocumentHandlerType::Board : DocumentHandlerType::Desktop);
		});

		$openUrl = null;
		$urlManager = Driver::getInstance()->getUrlManager();
		$editUrl = new Uri($urlManager->getUrlForStartEditFile($newFile->getId(), self::CODE));
		$newFileUls = $this->unifiedLinkAccessService->getUlsForObjectId($newFile->getId());

		$this->unifiedLinkUrl->addUlsToUri(
			uri: $editUrl,
			uls: $newFileUls,
		);

		if ($type === 'board')
		{
			$openUrl = $urlManager->getUrlForViewBoard($newFile, false, $calledFromDisk ? 'disk_page' : 'docs_attach');
		}

		$this->sendJsonSuccessResponse(array(
			'ufValue' => FileUserType::NEW_FILE_PREFIX . $newFile->getId(),
			'id' => $newFile->getId(),
			'object' => array(
				'id' => $newFile->getId(),
				'name' => $newFile->getName(),
				'uniqueCode' => $newFile->getUniqueCode(),
				'uls' => $newFileUls,
				'sizeInt' => $newFile->getSize(),
				'size' => \CFile::formatSize($newFile->getSize()),
				'extension' => $newFile->getExtension(),
				'nameWithoutExtension' => getFileNameWithoutExtension($newFile->getName()),
			),
			'folderName' => $storage->getProxyType()->getTitleForCurrentUser() . ' / ' . $folder->getName(),
			'uls' => $newFileUls,
			'link' => (string)$editUrl,
			'openUrl' => $openUrl,
		));
	}

	/**
	 * Writes the blank document the user asked for into the folder.
	 *
	 * The document is added to the Disk by the user, so it is written like anything else they add:
	 * the write path declares no context of its own and a format the portal restricts is refused
	 * here, in the "Add" menu, the same way it is refused on upload.
	 *
	 * @param Folder $folder Folder the document is written into.
	 * @param BlankFileData $fileData Name, type and source of the blank document.
	 * @param string $type Type of the document asked for.
	 * @param int $createdBy Id of the user the document is written on behalf of.
	 * @return File|null
	 */
	protected function createBlankFile(Folder $folder, BlankFileData $fileData, string $type, int $createdBy): ?File
	{
		return $folder->uploadFile(\CFile::makeFileArray($fileData->getSrc()), [
			'NAME' => $fileData->getName(),
			'CREATED_BY' => $createdBy,
		], [], true);
	}

	/**
	 * Puts the errors of a refused creation into the answer. The interface shows the first error of
	 * the answer, so a refusal the module names - a format the portal restricts, among them - is
	 * answered by itself; anything else a write may fail on is no text for the user and keeps the
	 * general message of the action ahead of it.
	 *
	 * The heir answers the write it makes of its own - the fork of an attachment into the personal
	 * storage of the user - the same way, so the answer is built here rather than in each of them.
	 *
	 * @param \Bitrix\Main\Error[] $writeErrors Errors the write was refused with.
	 * @return void
	 */
	protected function addErrorsOfRefusedCreation(array $writeErrors): void
	{
		if (WriteErrorTransportMap::findMappedRefusalCode($writeErrors) === null)
		{
			$this->errorCollection->addOne(new Error(
				Loc::getMessage('DISK_LOCAL_DOC_CONTROLLER_ERROR_COULD_NOT_CREATE_FILE'),
				self::ERROR_COULD_NOT_CREATE_FILE
			));
		}

		$this->errorCollection->add($writeErrors);
	}
}
