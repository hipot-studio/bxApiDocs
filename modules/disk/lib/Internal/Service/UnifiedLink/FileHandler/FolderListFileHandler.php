<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\UrlManager;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;

readonly class FolderListFileHandler implements HtmlRenderableFileHandler
{
	/**
	 * @param UnifiedLinkAccessService $accessService
	 * @param File $file
	 * @param CurrentUser $currentUser
	 */
	public function __construct(
		protected UnifiedLinkAccessService $accessService,
		protected File $file,
		protected CurrentUser $currentUser,
	)
	{
	}

	public function view(): FileHandlerOperationResult
	{
		$uniqueCode = $this->file->getUniqueCode();

		if (!is_string($uniqueCode) || $uniqueCode === '')
		{
			$error = new Error('empty unique code of file');
			$errorCollection = new ErrorCollection([$error]);

			return FileHandlerOperationResult::createError($errorCollection);
		}

		$userId = $this->currentUser->getId();
		$userDiskUrl = (new UrlManager())->getUrlForUserDisk($userId);

		$userDiskUrl->addParams([
			'show_file_code' => $uniqueCode,
		]);

		return FileHandlerOperationResult::createSuccess(
			value: '',
			redirectUrl: (string)$userDiskUrl,
		);
	}

	public function edit(): FileHandlerOperationResult
	{
		$error = new Error('not supported');
		$errorCollection = new ErrorCollection([$error]);

		return FileHandlerOperationResult::createError($errorCollection);
	}
}
