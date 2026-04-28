<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Controller\Filter\CheckDiskFileAccess;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Service\FileService;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;

/**
 * File controller for im.v2.File.* methods.
 *
 * Provides single-call file upload to chat (replaces legacy 3-step flow:
 * im.disk.folder.get → disk.folder.uploadFile → im.disk.file.commit).
 */
class File extends BaseController
{
	public function configureActions(): array
	{
		return [
			'upload' => [
				'+prefilters' => [
					new CheckActionAccess(Action::Send),
				],
			],
			'download' => [
				'+prefilters' => [
					new CheckDiskFileAccess(),
				],
			],
		];
	}

	public function getAutoWiredParameters()
	{
		return array_merge(parent::getAutoWiredParameters(), [
			new ExactParameter(
				FileItem::class,
				'file',
				function ($className, int $fileId) {
					return FileItem::initByDiskFileId($fileId);
				}
			),
		]);
	}

	/**
	 * @restMethod im.v2.File.upload
	 */
	public function uploadAction(
		Chat $chat,
		array $fields,
		CurrentUser $currentUser,
	): ?array
	{
		$result = (new FileService())->uploadFileToChat(
			$chat,
			$fields['name'] ?? '',
			$fields['content'] ?? '',
			(int)$currentUser->getId(),
			$fields['message'] ?? null,
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * @restMethod im.v2.File.download
	 */
	public function downloadAction(
		?FileItem $file,
		?\CRestServer $restServer = null,
	): ?array
	{
		if ($file === null)
		{
			$this->addError(new \Bitrix\Main\Error('File not found', 'FILE_NOT_FOUND'));

			return null;
		}

		$result = (new FileService())->resolveDownloadUrl($file, $restServer);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
