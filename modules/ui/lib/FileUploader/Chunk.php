<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\IO;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\ConfigurationValidator;

class Chunk
{
	protected int $size = 0;
	protected ?int $fileSize = null;
	protected ?int $startRange = null;
	protected ?int $endRange = null;
	protected string $type = '';
	protected string $name = '';
	protected int $width = 0;
	protected int $height = 0;

	protected IO\File $file;

	public function __construct(IO\File $file)
	{
		$this->setFile($file);
	}

	/**
	 * @deprecated use ChunkFactory::createFromRequest() instead
	 * @param HttpRequest $request
	 * @return Result
	 */
	public static function createFromRequest(HttpRequest $request): Result
	{
		return ChunkFactory::createFromRequest($request);
	}

	/**
	 * @deprecated use ConfigurationValidator::validateChunk() instead
	 * @param Configuration $config
	 * @return Result
	 */
	public function validate(Configuration $config): Result
	{
		$validator = new ConfigurationValidator($config);
		return $validator->validateChunk($this);
	}

	public function getFile(): IO\File
	{
		return $this->file;
	}

	/**
	 * @internal
	 */
	public function setFile(IO\File $file): void
	{
		$this->file = $file;
		$this->size = $file->getSize();
	}

	public function getSize(): int
	{
		return $this->size;
	}

	public function getFileSize(): int
	{
		return $this->fileSize ?? $this->size;
	}

	public function setFileSize(int $fileSize): void
	{
		$this->fileSize = $fileSize;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function setType(string $type): void
	{
		$this->type = $type;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function setName(string $name): void
	{
		$this->name = $name;
	}

	public function getWidth(): int
	{
		return $this->width;
	}

	public function setWidth(int $width): void
	{
		$this->width = $width;
	}

	public function getHeight(): int
	{
		return $this->height;
	}

	public function setHeight(int $height): void
	{
		$this->height = $height;
	}

	public function getStartRange(): ?int
	{
		return $this->startRange;
	}

	public function setStartRange(int $startRange): void
	{
		$this->startRange = $startRange;
	}

	public function getEndRange(): ?int
	{
		return $this->endRange;
	}

	public function setEndRange(int $endRange): void
	{
		$this->endRange = $endRange;
	}

	public function isFirst(): bool
	{
		return $this->startRange === null || $this->startRange === 0;
	}

	public function isLast(): bool
	{
		return $this->endRange === null || ($this->endRange + 1) === $this->fileSize;
	}

	public function isOnlyOne(): bool
	{
		return (
			$this->startRange === null
			|| ($this->startRange === 0 && ($this->endRange - $this->startRange + 1) === $this->fileSize)
		);
	}
}
