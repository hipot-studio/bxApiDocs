<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

final class RenderResult
{
	public function __construct(
		private readonly string $content,
		private readonly int $status,
		private readonly ?string $redirectUrl = null,
		private readonly ?string $component = null,
	) {
	}

	public function getContent(): string
	{
		return $this->content;
	}

	public function getStatus(): int
	{
		return $this->status;
	}

	public function getRedirectUrl(): ?string
	{
		return $this->redirectUrl;
	}

	public function getComponent(): ?string
	{
		return $this->component;
	}
}
