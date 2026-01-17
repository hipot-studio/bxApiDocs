<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Transcription\Item;

use Bitrix\Im\V2\Integration\AI\Transcription\Util\EmotionParser;

class TranscribeFileItem
{
	private ?string $plainText = null;

	private function __construct(
		public readonly int $fileId,
		public readonly int $diskFileId,
		public readonly int $chatId,
		public readonly Status $status,
		public readonly ?string $transcriptText,
	) {}

	public static function create(
		int $fileId,
		int $diskFileId,
		int $chatId,
		Status $status,
		?string $transcriptText
	): TranscribeFileItem
	{
		return new self($fileId, $diskFileId, $chatId, $status, $transcriptText);
	}

	public static function createByError(int $fileId, int $diskFileId, int $chatId): TranscribeFileItem
	{
		return new self($fileId, $diskFileId, $chatId, Status::Error, null);
	}

	public static function createByPending(int $fileId, int $diskFileId, int $chatId): TranscribeFileItem
	{
		return new self($fileId, $diskFileId, $chatId, Status::Pending, null);
	}

	public function toRestFormat(): array
	{
		return [
			'fileId' => $this->diskFileId,
			'status' => $this->status->value,
			'transcriptText' => $this->transcriptText,
		];
	}

	public function getPlainText(): ?string
	{
		if ($this->transcriptText === null)
		{
			return null;
		}

		$this->plainText ??= EmotionParser::stripEmotionBlocks($this->transcriptText);

		return $this->plainText;
	}
}
