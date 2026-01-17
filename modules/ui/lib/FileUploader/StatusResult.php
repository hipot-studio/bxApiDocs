<?php

namespace Bitrix\UI\FileUploader;

class StatusResult extends \Bitrix\Main\Result implements \JsonSerializable
{
	protected ?FileInfo $file = null;
	protected ?string $token = null;
	protected bool $done = false;
	protected int $receivedSize = 0;
	protected int $size = 0;

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

	public function getSize(): int
	{
		return $this->size;
	}

	public function setSize(int $size): void
	{
		$this->size = $size;
	}

	public function getReceivedSize(): int
	{
		return $this->receivedSize;
	}

	public function setReceivedSize(int $receivedSize): void
	{
		$this->receivedSize = $receivedSize;
	}

	public function jsonSerialize(): array
	{
		return [
			'token' => $this->getToken(),
			'done' => $this->isDone(),
			'size' => $this->getSize(),
			'receivedSize' => $this->getReceivedSize(),
			'file' => $this->getFileInfo(),
		];
	}
}
