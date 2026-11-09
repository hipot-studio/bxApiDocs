<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Markup\UploadBuffer;

readonly class PutMarkupFileDto
{
	public const MODE_CREATE = UploadBuffer::MODE_CREATE;
	public const MODE_UPDATE = UploadBuffer::MODE_UPDATE;

	private function __construct(
		public string $uploadId = '',
		public string $mode = '',
		public int $bindingId = 0,
		public string $path = '',
		public string $content = '',
		public string $encoding = UploadBuffer::ENCODING_TEXT,
		public bool $hasContent = false,
		public ?int $offset = null,
		public bool $final = false,
		public string $sha256 = '',
		public int $diskFileId = 0,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			uploadId: is_string($props['uploadId'] ?? null) ? trim($props['uploadId']) : '',
			mode: is_string($props['mode'] ?? null) ? trim($props['mode']) : '',
			bindingId: self::normalizePositiveInteger($props[AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD] ?? null),
			path: is_scalar($props['path'] ?? null) ? trim((string)$props['path']) : '',
			content: is_scalar($props['content'] ?? null) ? (string)$props['content'] : '',
			encoding: self::normalizeEncoding($props['encoding'] ?? null),
			hasContent: is_scalar($props['content'] ?? null),
			offset: self::normalizeOffset($props['offset'] ?? null),
			final: filter_var($props['final'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true,
			sha256: is_string($props['sha256'] ?? null) ? mb_strtolower(trim($props['sha256'])) : '',
			diskFileId: self::normalizePositiveInteger($props['diskFileId'] ?? null),
		);
	}

	/**
	 * Inventory mode: only the buffer is named, no file and no source.
	 */
	public function isInventory(): bool
	{
		return $this->uploadId !== ''
			&& $this->path === ''
			&& !$this->hasContent
			&& $this->offset === null
			&& !$this->final
			&& $this->diskFileId <= 0
		;
	}

	/**
	 * Null for an absent offset, -1 for a malformed one: a malformed offset must not
	 * turn a part into a whole file.
	 */
	private static function normalizeOffset(mixed $value): ?int
	{
		if ($value === null)
		{
			return null;
		}

		$parsed = is_scalar($value) && !is_bool($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

		return $parsed !== false && $parsed >= 0 ? (int)$parsed : -1;
	}

	private static function normalizeEncoding(mixed $value): string
	{
		$encoding = is_string($value) ? trim($value) : '';

		return $encoding === UploadBuffer::ENCODING_BASE64
			? UploadBuffer::ENCODING_BASE64
			: UploadBuffer::ENCODING_TEXT;
	}

	private static function normalizePositiveInteger(mixed $value): int
	{
		if ($value === null || is_bool($value) || !is_scalar($value))
		{
			return 0;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_INT);

		return $parsed !== false ? max(0, (int)$parsed) : 0;
	}
}
