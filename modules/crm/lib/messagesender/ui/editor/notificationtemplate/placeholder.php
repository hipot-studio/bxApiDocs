<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor\NotificationTemplate;

final class Placeholder implements \JsonSerializable
{
	private ?string $value = null;
	private ?string $caption = null;

	public function __construct(
		/** For example, DOCUMENT_URL or URL */
		private readonly string $name,
	)
	{
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getValue(): ?string
	{
		return $this->value;
	}

	public function setValue(?string $value): self
	{
		$this->value = $value;

		return $this;
	}

	public function getCaption(): ?string
	{
		return $this->caption;
	}

	public function setCaption(?string $caption): self
	{
		$this->caption = $caption;

		return $this;
	}

	public function jsonSerialize(): array
	{
		return [
			'name' => $this->name,
			'value' => $this->value,
			'caption' => $this->caption,
		];
	}
}
