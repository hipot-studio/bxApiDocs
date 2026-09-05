<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\Error;

class UploadResult extends \Bitrix\Main\Result implements \JsonSerializable
{
	protected ?TempFile $tempFile = null;
	protected ?FileInfo $file = null;
	protected ?string $token = null;
	protected bool $done = false;
	protected ?string $strategy = null;
	protected ?int $partSize = null;
	protected ?int $partCount = null;
	protected ?array $presignedParts = null;

	public static function reject(Error $error): self
	{
		$result = new static();
		$result->addError($error);

		return $result;
	}

	/**
	 * The Uploader retries an upload request if a server returns a non-uploader error.
	 * That's why we need to convert an error from Error to UploaderError.
	 */
	public function addError(Error $error)
	{
		if ($error instanceof UploaderError)
		{
			return parent::addError($error);
		}
		else
		{
			return parent::addError(new UploaderError(
				$error->getCode(),
				$error->getMessage(),
				$error->getCustomData()
			));
		}
	}

	public function addErrors(array $errors)
	{
		foreach ($errors as $error)
		{
			$this->addError($error);
		}

		return $this;
	}

	public function getTempFile(): ?TempFile
	{
		return $this->tempFile;
	}

	public function setTempFile(TempFile $tempFile): void
	{
		$this->tempFile = $tempFile;
	}

	public function getFileInfo(): ?FileInfo
	{
		return $this->file;
	}

	public function setFileInfo(?FileInfo $file): void
	{
		$this->file = $file;
	}

	public function getToken(): ?string
	{
		return $this->token;
	}

	public function setToken(string $token)
	{
		$this->token = $token;
	}

	public function setDone(bool $done): void
	{
		$this->done = $done;
	}

	public function isDone(): bool
	{
		return $this->done;
	}

	public function getStrategy(): ?string
	{
		return $this->strategy;
	}

	public function setStrategy(?string $strategy): void
	{
		$this->strategy = $strategy;
	}

	public function getPartSize(): ?int
	{
		return $this->partSize;
	}

	public function setPartSize(?int $partSize): void
	{
		$this->partSize = $partSize;
	}

	public function getPartCount(): ?int
	{
		return $this->partCount;
	}

	public function setPartCount(?int $partCount): void
	{
		$this->partCount = $partCount;
	}

	public function getPresignedParts(): ?array
	{
		return $this->presignedParts;
	}

	public function setPresignedParts(?array $parts): void
	{
		$this->presignedParts = $parts;
	}

	public function jsonSerialize(): array
	{
		$data = [
			'token' => $this->getToken(),
			'done' => $this->isDone(),
			'file' => $this->getFileInfo(),
		];

		if ($this->getStrategy() !== null)
		{
			$data['strategy'] = $this->getStrategy();
			$data['partSize'] = $this->getPartSize();
			$data['partCount'] = $this->getPartCount();
		}

		if ($this->getPresignedParts() !== null)
		{
			$data['parts'] = $this->getPresignedParts();
		}

		return $data;
	}
}