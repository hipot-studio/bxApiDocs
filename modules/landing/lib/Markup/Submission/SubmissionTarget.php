<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup\Submission;

use Bitrix\Landing\Markup\Manifest;
use Bitrix\Landing\Markup\UploadBuffer;

/**
 * What a markup package is submitted to: a chat binding (create), a custom markup page with its
 * current manifest (update) or nothing at all (the dev entry creates a site without a chat).
 */
final class SubmissionTarget
{
	private function __construct(
		public readonly string $mode,
		public readonly int $bindingId = 0,
		public readonly int $pageId = 0,
		public readonly int $siteId = 0,
		public readonly ?Manifest $manifest = null,
	)
	{
	}

	public static function create(int $bindingId): self
	{
		return new self(UploadBuffer::MODE_CREATE, bindingId: $bindingId);
	}

	/**
	 * The manifest of the page resolves the unchanged files the submission references by identifier.
	 */
	public static function update(int $pageId, int $siteId, Manifest $manifest): self
	{
		return new self(UploadBuffer::MODE_UPDATE, pageId: $pageId, siteId: $siteId, manifest: $manifest);
	}

	public static function dev(): self
	{
		return new self(UploadBuffer::MODE_DEV);
	}

	public function isUpdate(): bool
	{
		return $this->mode === UploadBuffer::MODE_UPDATE;
	}

	public function isDev(): bool
	{
		return $this->mode === UploadBuffer::MODE_DEV;
	}

	/**
	 * The target identifier an upload buffer of this target is bound to.
	 */
	public function getBufferTargetId(): int
	{
		return match ($this->mode)
		{
			UploadBuffer::MODE_UPDATE => $this->pageId,
			UploadBuffer::MODE_CREATE => $this->bindingId,
			default => 0,
		};
	}
}
