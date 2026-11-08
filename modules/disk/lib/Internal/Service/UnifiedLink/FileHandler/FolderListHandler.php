<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\UrlManager;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;

readonly class FolderListHandler implements HtmlRenderableHandler
{
	/**
	 * @param UnifiedLinkAccessService $accessService
	 * @param UnifiedLinkUrl $unifiedLinkUrl
	 * @param BaseObject $baseObject
	 * @param CurrentUser $currentUser
	 */
	public function __construct(
		protected UnifiedLinkAccessService $accessService,
		protected UnifiedLinkUrl $unifiedLinkUrl,
		protected BaseObject $baseObject,
		protected CurrentUser $currentUser,
	)
	{
	}

	public function view(): HandlerOperationResult
	{
		if ($this->baseObject instanceof Folder)
		{
			return $this->viewFolder();
		}

		return $this->viewNonFolder();
	}

	/**
	 * @return HandlerOperationResult
	 * @throws ArgumentTypeException
	 */
	public function viewFolder(): HandlerOperationResult
	{
		/** @var Folder $folder */
		$folder = $this->baseObject;
		$url = $this->getUrlManager()->getPathFolderList($folder);
		$uls = $this->accessService->getUlsForObjectId($folder->getId());
		$url = $this->unifiedLinkUrl->addUlsToUrl($url, $uls);

		return HandlerOperationResult::createSuccess(
			value: '',
			redirectUrl: $url,
		);
	}

	/**
	 * @return HandlerOperationResult
	 */
	public function viewNonFolder(): HandlerOperationResult
	{
		$uniqueCode = $this->baseObject->getUniqueCode();

		if (!is_string($uniqueCode) || $uniqueCode === '')
		{
			$error = new Error('empty unique code of object');
			$errorCollection = new ErrorCollection([$error]);

			return HandlerOperationResult::createError($errorCollection);
		}

		$userId = $this->currentUser->getId();
		$userDiskUrl = $this->getUrlManager()->getUrlForUserDisk($userId);

		$userDiskUrl->addParams([
			'show_object_code' => $uniqueCode,
		]);

		return HandlerOperationResult::createSuccess(
			value: '',
			redirectUrl: (string)$userDiskUrl,
		);
	}

	public function edit(): HandlerOperationResult
	{
		$error = new Error('not supported');
		$errorCollection = new ErrorCollection([$error]);

		return HandlerOperationResult::createError($errorCollection);
	}

	/**
	 * @return UrlManager
	 */
	protected function getUrlManager(): UrlManager
	{
		return new UrlManager();
	}
}
