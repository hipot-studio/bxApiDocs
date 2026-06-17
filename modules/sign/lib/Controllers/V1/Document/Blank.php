<?php

namespace Bitrix\Sign\Controllers\V1\Document;

use Bitrix\Main;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Request;
use Bitrix\Main\SystemException;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute;
use Bitrix\Sign\Access\Service\BlankAccessService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Result\Service\Sign\CreateBlankArchiveResult;
use Bitrix\Sign\Upload\BlankUploadController;
use Bitrix\Sign\Type;
use Bitrix\Sign\Item;
use Bitrix\Sign\Type\Access\AccessibleItemType;
use Bitrix\Sign\Util\Filename;
use Bitrix\Sign\Util\Query\Db\Paginator;

class Blank extends \Bitrix\Sign\Engine\Controller
{

	public function __construct(Request $request = null)
	{
		parent::__construct($request);
		Loader::includeModule('ui');
	}

	public function configureActions(): array
	{
		$actionsConfiguration = parent::configureActions();
		$actionsConfiguration['downloadByDocument']['-prefilters'] = [
			Main\Engine\ActionFilter\ContentType::class,
			Main\Engine\ActionFilter\Csrf::class,
		];

		return $actionsConfiguration;
	}

	/**
	 * @param array $files
	 * @param string|null $scenario
	 * @param bool $forTemplate
	 * @param bool $hasPlaceholders
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function createAction(
		array $files,
		?string $scenario = null,
		bool $forTemplate = false,
		bool $hasPlaceholders = false,
	): array
	{
		/** @var array<int> $fileIds */
		$fileIds = [];
		foreach ($files as $fileId)
		{
			if (!is_string($fileId))
			{
				$this->addError(new Error("Invalid file id"));

				return [];
			}

			$fileController = new BlankUploadController([]);
			$uploader = new \Bitrix\UI\FileUploader\Uploader($fileController);
			$pendingFiles = $uploader->getPendingFiles([$fileId]);
			$pendingFiles->makePersistent();
			$file = $pendingFiles->get($fileId);
			$persistentFileId = $file?->getFileId();

			if ($persistentFileId === null)
			{
				$this->addError(new Error("Invalid file id"));

				return [];
			}
			$fileIds[] = $persistentFileId;
		}
		$scenario ??= Type\BlankScenario::B2B;
		$result = Container::instance()->getSignBlankService()->createFromFileIds(
			$fileIds,
			$scenario,
			$forTemplate,
			$hasPlaceholders,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$item = [
			'id' => $result->getId(),
			'userAvatarUrl' => null,
			'userName' => null,
			'hasPlaceholders' => $hasPlaceholders,
		];

		$userId = CurrentUser::get()->getId();
		if ($userId !== null)
		{
			$user = Container::instance()->getUserService()->getUserById($userId);
			if ($user !== null)
			{
				$item['userAvatarUrl'] = Container::instance()->getUserService()->getUserAvatar($user);
				$item['userName'] = Container::instance()->getUserService()->getUserName($user);
			}
		}

		return $item;
	}

	/**
	 * @param int $blankId
	 *
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function loadAction(int $blankId): array
	{
		$blank = Container::instance()
			->getBlankRepository()
			->getByIdAndValidatePermissions($blankId)
		;

		if (!$blank)
		{
			return [];
		}

		return get_object_vars($blank);
	}

	/**
	 * @param int $countPerPage
	 * @param int $page
	 * @param ?string $scenario
	 *
	 * @return array
	 */
	public function listAction(
		int $countPerPage = 10,
		int $page = 1,
		?string $scenario = null
	): array
	{
		$scenario ??= Type\BlankScenario::B2B;
		if (!in_array($scenario, Type\BlankScenario::getAll(), true))
		{
			$this->addError(new Error('Wrong blank scenario'));
			return [];
		}

		if ($countPerPage <= 0)
		{
			$this->addError(new Error('Blanks count must be greater than 0. Now: '.$countPerPage));

			return [];
		}
		if ($page <= 0)
		{
			$this->addError(new Error('Blanks page must be greater than 0. Now: '.$page));

			return [];
		}

		[$limit, $offset] = Paginator::getLimitAndOffset($countPerPage, $page);

		$data = Container::instance()
			->getBlankRepository()
			->getPublicList($limit, $offset, $scenario)
			->toArray();

		return array_map(
			function (Item\Blank $blank): array {
				$item = (array)$blank;
				$item['previewUrl'] = null;
				$item['userAvatarUrl'] = null;
				$item['userName'] = null;
				if ($blank->id !== null)
				{
					$resource = Container::instance()->getBlankResourceRepository()->getFirstByBlankId($blank->id);
					if ($resource !== null)
					{
						$item['previewUrl'] = Container::instance()->getFileRepository()->getFileSrc($resource->fileId);
					}
				}
				if ($blank->createdById !== null)
				{
					$user = Container::instance()->getUserService()->getUserById($blank->createdById);
					if ($user !== null)
					{
						$item['userAvatarUrl'] = Container::instance()->getUserService()->getUserAvatar($user);
						$item['userName'] = Container::instance()->getUserService()->getUserName($user);
					}
				}

				return $item;
			},
			$data
		);
	}

	public function getByIdAction(
		int $id,
		BlankAccessService $blankAccessService,
	): array
	{
		$container = Container::instance();
		$blankRepository = $container->getBlankRepository();
		$blank = $blankRepository->getByIdAndValidatePermissions($id);
		if ($blank === null)
		{
			$blank = $blankRepository->getById($id);
			$userId = (int)$this->getCurrentUser()?->getId();
			if ($blank === null || !$blankAccessService->isUserHasReadAccessThroughLinkedDocuments($userId, $blank))
			{
				$this->addError(new Error("Blank with id `$id` doesnt exist", "DOESNT_EXIST"));

				return [];
			}
		}
		$result = [
			'id' => $blank->id,
			'title' => $blank->title,
			'status' => $blank->status,
			'userAvatarUrl' => null,
			'userName' => null,
			'previewUrl' => null,
			'dateCreate' => $blank->dateCreate
		];
		$resource = $container->getBlankResourceRepository()->getFirstByBlankId($blank->id);
		if ($resource !== null)
		{
			$result['previewUrl'] = $container->getFileRepository()->getFileSrc($resource->fileId);
		}
		if ($blank->createdById !== null)
		{
			$user = $container->getUserService()->getUserById($blank->createdById);
			if ($user !== null)
			{
				$result['userAvatarUrl'] = $container->getUserService()->getUserAvatar($user);
				$result['userName'] = $container->getUserService()->getUserName($user);
			}
		}

		return $result;
	}

	#[Attribute\Access\LogicOr(
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_DOCUMENT_READ,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentId',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_DOCUMENT_READ,
			itemType: AccessibleItemType::DOCUMENT,
			itemIdOrUidRequestKey: 'documentId',
		),
		new Attribute\ActionAccess(
			permission: ActionDictionary::ACTION_B2E_TEMPLATE_READ,
		),
	)]
	public function downloadByDocumentAction(int $documentId): Main\Engine\Response\BFile|Main\Engine\Response\File|array
	{
		$document = $this->container->getDocumentRepository()->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error('Document not found', 'DOCUMENT_NOT_FOUND'));

			return [];
		}

		$accessController = $this->getAccessController();
		$readPermission = Type\DocumentScenario::isB2eScenarioByDocument($document)
			? ActionDictionary::ACTION_B2E_DOCUMENT_READ
			: ActionDictionary::ACTION_DOCUMENT_READ
		;
		if (!$accessController->checkByItem($readPermission, $document))
		{
			$this->addError(new Error('Access denied', 'ACCESS_DENIED'));

			return [];
		}

		$blank = $this->container->getBlankRepository()->getById($document->blankId);
		if ($blank === null)
		{
			$this->addError(new Error('Blank not found', 'BLANK_NOT_FOUND'));

			return [];
		}

		if (!$this->container->getSignBlankService()->hasDownloadableFile($blank))
		{
			$this->addError(new Error('Blank has no files', 'BLANK_NO_FILES'));

			return [];
		}

		if ($blank->fileCollection->count() === 1)
		{
			$file = $blank->fileCollection->first();
			$fileArray = \CFile::GetFileArray($file->id);
			$originalName = (string)($fileArray['ORIGINAL_NAME'] ?? '');
			$downloadName = $originalName !== ''
				? Filename::compose($originalName, $document->title)
				: null
			;

			return Main\Engine\Response\BFile::createByFileData($fileArray, $downloadName)
				->showInline(false)
			;
		}

		$result = $this->container->getSignBlankArchiveService()->createFromBlank($blank);
		if (!$result instanceof CreateBlankArchiveResult)
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return (new Main\Engine\Response\File($result->filePath, $result->fileName, 'application/zip'))
			->showInline(false)
		;
	}
}