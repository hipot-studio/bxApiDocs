<?php

namespace Bitrix\Crm\MessageSender\UI;

use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\Channel\Correspondents;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Type;

/**
 * A view model for Channel
 */
final class ViewChannel implements \JsonSerializable
{
	public function __construct(
		private readonly string $id,
		private readonly Channel $backend,
		private readonly Type $type,
		private readonly Appearance $appearance,
		private readonly ?array $fromList = null,
		private readonly ?bool $isConnected = null,
		private readonly ?string $connectionUrl = null,
		private readonly bool $isPromo = false,
		private readonly ?string $sliderCode = null,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getAppearance(): Appearance
	{
		return $this->appearance;
	}

	public function getType(): Type
	{
		return $this->type;
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

	/**
	 * Returns a list of 'from' correspondents for the channel. It can be a subset of backend's list for UI reasons.
	 *
	 * @return Correspondents\From[]
	 */
	public function getFromList(): array
	{
		return $this->fromList ?? $this->backend->getFromList();
	}

	/**
	 * @return Correspondents\To[]
	 */
	public function getToList(): array
	{
		return $this->backend->getToList();
	}

	public function isConnected(): bool
	{
		return $this->isConnected ?? $this->backend->checkChannel()->isSuccess();
	}

	public function getConnectionUrl(): string
	{
		return $this->connectionUrl ?? $this->backend->getSender()::getConnectUrl();
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
			'type' => $this->type->value,
			'appearance' => $this->appearance,
			'fromList' => $this->getFromList(),
			'toList' => $this->getToList(),
			'isConnected' => $this->isConnected(),
			'connectionUrl' => $this->getConnectionUrl(),
			'isPromo' => $this->isPromo,
			'isTemplatesBased' => $this->backend->isTemplatesBased(),
			'isLocked' => $this->isLocked(),
			'sliderCode' => $this->getSliderCode(),
		];
	}
}
