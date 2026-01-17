<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\UI\Editor;

use Bitrix\Crm\MessageSender\UI\ViewChannel\Icon;
use Bitrix\Main\ArgumentException;

final class PromoBanner implements \JsonSerializable
{
	public function __construct(
		private readonly string $id,
		private readonly string $title,
		private readonly string $subtitle,
		private readonly string $background,
		private readonly ?Icon $icon,
		private readonly ?string $customIconName,
		private readonly string $connectionUrl,
	)
	{
		if ($this->icon === null && $this->customIconName === null)
		{
			throw new ArgumentException('Either icon or customIconName must be provided');
		}
	}

	public function getId(): string
	{
		return $this->id;
	}

	/**
	 * @return string
	 */
	public function getTitle(): string
	{
		return $this->title;
	}

	/**
	 * @return string
	 */
	public function getSubtitle(): string
	{
		return $this->subtitle;
	}

	/**
	 * @return string
	 */
	public function getBackground(): string
	{
		return $this->background;
	}

	/**
	 * @return Icon|null
	 */
	public function getIcon(): ?Icon
	{
		return $this->icon;
	}

	/**
	 * @return string|null
	 */
	public function getCustomIconName(): ?string
	{
		return $this->customIconName;
	}

	public function getConnectionUrl(): string
	{
		return $this->connectionUrl;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
			'title' => $this->getTitle(),
			'subtitle' => $this->getSubtitle(),
			'background' => $this->getBackground(),
			'icon' => $this->getIcon(),
			'customIconName' => $this->getCustomIconName(),
			'connectionUrl' => $this->getConnectionUrl(),
		];
	}
}
