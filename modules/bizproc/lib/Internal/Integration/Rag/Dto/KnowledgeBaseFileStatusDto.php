<?php

namespace Bitrix\Bizproc\Internal\Integration\Rag\Dto;

use Bitrix\Bizproc\Internal\Integration\Rag\FileStatus;
use Bitrix\Main\Type\Contract\Arrayable;

class KnowledgeBaseFileStatusDto implements Arrayable
{
	public function __construct(
		public readonly int $fileId,
		public readonly ?FileStatus $status = null,
		public ?string $fileName = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'fileId' => $this->fileId,
			'status' => $this->status->value,
			'fileName' => $this->fileName,
		];
	}
}