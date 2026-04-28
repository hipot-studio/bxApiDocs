<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Controller\Filter\CheckDiskFileAccess;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Service\FileService;
use Bitrix\Main\Engine\AutoWire\ExactParameter;

class File extends BotController
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

	public function getAutoWiredParameters(): array
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
	 * @restMethod imbot.v2.File.upload
	 */
	public function uploadAction(
		Chat $chat,
		array $fields,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$result = (new FileService())->uploadFileToChat(
			$chat,
			$fields['name'] ?? '',
			$fields['content'] ?? '',
			$this->getBotUserId(),
			$fields['message'] ?? null,
		);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->filterOutput($result->getData());
	}

	/**
	 * @restMethod imbot.v2.File.download
	 */
	public function downloadAction(
		?FileItem $file,
		?\CRestServer $restServer = null,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		if ($file === null)
		{
			$this->addError(new FileError(FileError::NOT_FOUND));

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
