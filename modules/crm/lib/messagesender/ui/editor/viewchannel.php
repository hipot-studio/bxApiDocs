<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor;

use Bitrix\Crm\MessageSender\Channel;
use Bitrix\Crm\MessageSender\Channel\Correspondents;
use Bitrix\Crm\MessageSender\UI\ViewChannel\Appearance;

/**
 * A view model for Channel for editor
 */
final class ViewChannel implements \JsonSerializable
{
	public function __construct(
		private readonly string $id,
		private readonly Channel $backend,
		private readonly Appearance $appearance,
		private readonly ?array $fromList = null,
		private readonly ?bool $isConnected = null,
		private readonly bool $isPromo = false,
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

	public function isPromo(): bool
	{
		return $this->isPromo;
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
			'appearance' => $this->appearance,
			'fromList' => $this->getFromList(),
			'toList' => $this->getToList(),
			'isConnected' => $this->isConnected(),
			'isPromo' => $this->isPromo,
			'isTemplatesBased' => $this->backend->isTemplatesBased(),
		];
	}
}
