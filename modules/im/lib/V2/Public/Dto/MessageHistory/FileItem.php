<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Dto\MessageHistory;

final class FileItem implements \JsonSerializable
{
	/**
	 * @param int $diskId File ID in the disk module.
	 * @param string $name Original file name.
	 * @param int $size File size in bytes.
	 * @param string $type Content category (image, video, audio, file).
	 * @param string|null $transcriptionText Transcription text.
	 */
	public function __construct(
		public readonly int $diskId,
		public readonly string $name,
		public readonly int $size,
		public readonly string $type,
		public readonly ?string $transcriptionText = null,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'diskId' => $this->diskId,
			'name' => $this->name,
			'size' => $this->size,
			'type' => $this->type,
			'transcriptionText' => $this->transcriptionText,
		];
	}
}
