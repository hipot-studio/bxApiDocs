<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\ConnectionsSlider\Section;

use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;

/**
 * A view model for Channel for connection slider section
 */
final class ViewChannel implements \JsonSerializable
{
	public function __construct(
		private readonly string $id,
		private readonly Channel $backend,
		private readonly Appearance $appearance,
		private readonly ?bool $isConnected = null,
		private readonly ?string $connectionUrl = null,
		private readonly bool $isPromo = false,
		private readonly ?string $sliderCode = null,
	)
	{
	}

	public static function fromEditorViewChannel(
		\Bitrix\Crm\MessageSender\UI\Editor\ViewChannel $editorViewChannel,
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
		return $this->isConnected ?? $this->backend->checkChannel()->isSuccess();
	}

	public function getConnectionUrl(): ?string
	{
		return $this->connectionUrl;
	}

	public function getBackend(): Channel
	{
		return $this->backend;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'backend' => [
				'senderCode' => $this->backend->getSender()::getSenderCode(),
				'id' => $this->backend->getId(),
			],
			'appearance' => $this->getAppearance(),
			'isConnected' => $this->isConnected(),
			'connectionUrl' => $this->getConnectionUrl(),
			'isPromo' => $this->isPromo(),
			'isLocked' => $this->isLocked(),
			'sliderCode' => $this->getSliderCode(),
		];
	}
}
