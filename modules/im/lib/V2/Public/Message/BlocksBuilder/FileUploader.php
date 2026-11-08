<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Loader;

/**
 * Puts a file of a future block into the chat.
 *
 * A block names its files by id, and a file is only accepted by a block when it lies in the folder of
 * that chat. This is the way to get such an id: the uploader puts the content into the chat folder and
 * marks the file ready, so the producer does not have to repeat what the chat expects of a file.
 */
class FileUploader
{
	public function __construct(private readonly Chat $chat)
	{}

	/**
	 * @return Result the id of the uploaded file, ready to be given to a block
	 */
	public function uploadContent(string $fileName, string $content, ?int $authorId = null): Result
	{
		return $this->uploadFile(
			['name' => $fileName, 'content' => $content],
			$authorId
		);
	}

	/**
	 * @return Result the id of the uploaded file, ready to be given to a block
	 */
	public function uploadLocalFile(string $path, ?string $fileName = null, ?int $authorId = null): Result
	{
		$result = new Result();

		if (!is_file($path) || !is_readable($path))
		{
			return $result->addError(new BuilderError(BuilderError::FILE_UPLOAD_FAILED));
		}

		return $this->uploadFile(
			['name' => $fileName ?? basename($path), 'tmp_name' => $path],
			$authorId
		);
	}

	private function uploadFile(array $file, ?int $authorId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('disk'))
		{
			return $result->addError(new BuilderError(BuilderError::FILE_UPLOAD_FAILED));
		}

		$folder = \CIMDisk::GetFolderModel($this->chat->getChatId());
		if (!$folder)
		{
			return $result->addError(new BuilderError(BuilderError::FILE_UPLOAD_FAILED));
		}

		$diskFile = $folder->uploadFile(
			$file,
			['CREATED_BY' => $authorId ?? $this->chat->getContext()->getUserId()],
			[],
			true
		);

		if (!$diskFile)
		{
			return $result->addError(new BuilderError(BuilderError::FILE_UPLOAD_FAILED));
		}

		// The chat shows a file as uploading until its content version is raised - see BuilderService.
		$diskFile->increaseGlobalContentVersion();

		return $result->setResult((int)$diskFile->getId());
	}
}
