<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\ConnectionsSlider\Section;

use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Backend;

/**
 * A view model for Channel for connection slider section
 */
final readonly class ViewChannel implements \JsonSerializable
{
	public function __construct(
		private string $id,
		private Backend $backend,
		private Appearance $appearance,
		private bool $isConnected,
		private ?string $connectionUrl = null,
		private bool $isPromo = false,
		private ?string $sliderCode = null,
	)
	{
	}

	public static function fromEditorViewChannel(
		\Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel $editorViewChannel,
		?string $connectionUrl = null,
		?string $sliderCode = null,
	): self
	{
		return new self(
			$editorViewChannel->getId(),
			$editorViewChannel->getBackend(),
			$editorViewChannel->getAppearance(),
			$editorViewChannel->isConnected(),
			$connectionUrl,
			$editorViewChannel->isPromo(),
			$sliderCode,
		);
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getAppearance(): Appearance
	{
		return $this->appearance;
	}

	public function isPromo(): bool
	{
		return $this->isPromo;
	}

	public function isLocked(): bool
	{
		return $this->sliderCode !== null;
	}

	public function getSliderCode(): ?string
	{
		return $this->sliderCode;
	}

	public function isConnected(): bool
	{
		return $this->isConnected;
	}

	public function getConnectionUrl(): ?string
	{
		return $this->connectionUrl;
	}

	public function getBackend(): Backend
	{
		return $this->backend;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'backend' => $this->backend,
			'appearance' => $this->getAppearance(),
			'isConnected' => $this->isConnected(),
			'connectionUrl' => $this->getConnectionUrl(),
			'isPromo' => $this->isPromo(),
			'isLocked' => $this->isLocked(),
			'sliderCode' => $this->getSliderCode(),
		];
	}
}
