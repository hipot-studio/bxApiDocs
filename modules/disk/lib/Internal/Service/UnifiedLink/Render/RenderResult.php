<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

final readonly class RenderResult
{
	public function __construct(
		private string $content,
		private int $status,
		private ?string $redirectUrl = null,
		private ?string $component = null,
		private array $headers = [],
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

	public function getHeaders(): array
	{
		return $this->headers;
	}
}
