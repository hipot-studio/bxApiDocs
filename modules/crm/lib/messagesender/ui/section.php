<?php

namespace Bitrix\Crm\MessageSender\UI;

final class Section implements \JsonSerializable
{
	/**
	 * @param string $title
	 * @param array<ViewChannel> $viewChannels
	 * @param string|null $description
	 * @param string|null $color
	 * @param string|null $iconPath
	 */
	public function __construct(
		private readonly string $title, //todo its not text, its brand logo. maybe logo and title as fallback
		private readonly array $viewChannels,
		private readonly ?string $description = null,
		private readonly ?string $color = null,
		private readonly ?string $iconPath = null,
	)
	{
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	/** @return array<ViewChannel> */
	public function getViewChannels(): array
	{
		return $this->viewChannels;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function getColor(): ?string
	{
		return $this->color;
	}

	public function getIconPath(): ?string
	{
		return $this->iconPath;
	}

	/**
	 * Returns a new Section instance with channels filtered by the provided callable.
	 *
	 * @param callable(ViewChannel): bool $filter
	 *
	 * @return self
	 */
	public function filterViewChannels(callable $filter): self
	{
		$filteredChannels = array_filter($this->viewChannels, $filter);

		return new self(
			$this->title,
			array_values($filteredChannels),
			$this->description,
			$this->color,
			$this->iconPath,
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'color' => $this->getColor(),
			'channels' => $this->getViewChannels(),
			'iconPath' => $this->getIconPath(),
		];
	}
}
