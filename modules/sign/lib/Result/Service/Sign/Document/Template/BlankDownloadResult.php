<?php

namespace Bitrix\Sign\Result\Service\Sign\Document\Template;

use Bitrix\Sign\Result\SuccessResult;

class BlankDownloadResult extends SuccessResult
{
	private function __construct(
		public readonly string $fileName,
		public readonly int $skippedCount,
		public readonly ?int $fileId = null,
		public readonly ?string $archivePath = null,
	)
	{
	}

	public static function createForFile(int $fileId, string $fileName, int $skippedCount): self
	{
		return new self($fileName, $skippedCount, fileId: $fileId);
	}

	public static function createForArchive(string $archivePath, string $fileName, int $skippedCount): self
	{
		return new self($fileName, $skippedCount, archivePath: $archivePath);
	}

	public function isArchive(): bool
	{
		return $this->archivePath !== null;
	}
}
