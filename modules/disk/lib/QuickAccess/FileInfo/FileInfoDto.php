<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

class FileInfoDto
{
	public function __construct (
		public readonly int $id,
		public readonly int $handlerId,
		public readonly int $width,
		public readonly int $height,
		public readonly string $path,
		public readonly string $dir,
		public readonly string $filename,
		public readonly string $contentType,
		public readonly int $expirationTime,
		public ?FileInfoDto $preview = null,
	)
	{
	}

	public function toArray(): array
	{
		$data = [
			'id' => $this->id ?? 0,
			'handlerId' => $this->handlerId ?? 0,
			'width' => $this->width ?? 0,
			'height' => $this->height ?? 0,
			'path' => $this->path ?? '0',
			'dir' => $this->dir ?? '0',
			'filename' => $this->filename ?? '0',
			'contentType' => $this->contentType ?? '0',
			'expirationTime' => $this->expirationTime ?? 0,
		];

		if (isset($this->preview))
		{
			$data['preview'] = $this->preview->toArray();
		}

		return $data;
	}
}