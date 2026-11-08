<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Image;

use Bitrix\Mail\Internals\SignatureImageTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\Result;
use Bitrix\Main\UuidGenerator;

final class SignatureImageService
{
	public const ERROR_COPY_FAILED = 'SIGNATURE_IMAGE_COPY_FAILED';
	public const ERROR_STORAGE_FAILED = 'SIGNATURE_IMAGE_STORAGE_FAILED';

	private SignatureImageValidator $validator;
	private \Closure $saveFile;
	private \Closure $addImage;
	private \Closure $deleteFile;
	private SignatureImageCreationGuard $creationGuard;

	public function __construct(
		?SignatureImageValidator $validator = null,
		?\Closure $saveFile = null,
		?\Closure $addImage = null,
		?\Closure $deleteFile = null,
		?SignatureImageCreationGuard $creationGuard = null,
	)
	{
		$this->validator = $validator ?? new SignatureImageValidator();
		$this->saveFile = $saveFile ?? static function (array $file): int|false {
			return \CFile::SaveFile($file, 'mail/signature', true);
		};
		$this->addImage = $addImage ?? static function (array $fields): AddResult {
			return SignatureImageTable::add($fields);
		};
		$this->deleteFile = $deleteFile ?? static function (int $fileId): void {
			\CFile::Delete($fileId);
		};
		$this->creationGuard = $creationGuard ?? new SignatureImageCreationGuard();
	}

	public function create(int $ownerId, array $file): Result
	{
		if ($ownerId <= 0)
		{
			return $this->failure(SignatureImageValidator::ERROR_UPLOAD_INVALID);
		}

		$validationResult = $this->validator->validate($file);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$data = $validationResult->getData();
		$normalizedFile = $data['file'];
		$normalizedFile['MODULE_ID'] = 'mail';
		$fileId = 0;
		$stored = false;

		try
		{
			return $this->creationGuard->execute($ownerId, function () use (
				$ownerId,
				$normalizedFile,
				$data,
				&$fileId,
				&$stored,
			): Result {
				$fileId = (int)($this->saveFile)($normalizedFile);
				if ($fileId <= 0)
				{
					return $this->failure(self::ERROR_COPY_FAILED);
				}

				$uuid = UuidGenerator::generateV4();
				$addResult = ($this->addImage)([
					'UUID' => $uuid,
					'FILE_ID' => $fileId,
					'OWNER_ID' => $ownerId,
					'CONTENT_TYPE' => $data['contentType'],
					'WIDTH' => $data['width'],
					'HEIGHT' => $data['height'],
					'FILE_SIZE' => $data['size'],
					'IS_ANIMATED' => $data['animated'] ? 'Y' : 'N',
					'STATE' => SignatureImageTable::STATE_PENDING,
				]);
				if (!$addResult instanceof AddResult || !$addResult->isSuccess())
				{
					$this->compensateFile($fileId);

					return $this->failure(self::ERROR_STORAGE_FAILED);
				}
				$stored = true;

				return (new Result())->setData([
					'imageId' => (int)$addResult->getId(),
					'uuid' => $uuid,
					'fileId' => $fileId,
					'contentType' => $data['contentType'],
					'width' => $data['width'],
					'height' => $data['height'],
					'size' => $data['size'],
					'animated' => $data['animated'],
					'variants' => $data['variants'],
				]);
			});
		}
		catch (\Throwable $exception)
		{
			if ($fileId > 0 && !$stored)
			{
				$this->compensateFile($fileId);
			}
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure(self::ERROR_STORAGE_FAILED);
		}
	}

	private function compensateFile(int $fileId): void
	{
		try
		{
			($this->deleteFile)($fileId);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	private function failure(string $code): Result
	{
		$result = new Result();
		$result->addError(new Error($code, $code));

		return $result;
	}
}
