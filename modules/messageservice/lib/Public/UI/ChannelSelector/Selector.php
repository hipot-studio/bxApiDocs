<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\ChannelSelector;

use Bitrix\MessageService\Public\UI\MessageEditor\Editor;
use Bitrix\MessageService\Public\UI\MessageEditor\Preferences\ChannelPosition;
use Bitrix\MessageService\Public\UI\MessageEditor\PromoBanner;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel as EditorViewChannel;

final class Selector implements \JsonSerializable
{
	private ?string $bindElement = null;
	/** @var ViewChannel[] */
	private array $viewChannels = [];
	/** @var PromoBanner[] */
	private array $promoBanners = [];
	/** @var ChannelPosition[] */
	private array $channelsSort = [];

	public static function fromEditor(Editor $editor): self
	{
		$viewChannels = array_map(
			static fn(EditorViewChannel $ch) => ViewChannel::fromEditorViewChannel($ch),
			$editor->getViewChannels() ?? [],
		);

		$preferences = $editor->getPreferences();
		$channelsSort = $preferences?->channelsSort ?? [];

		return (new self())
			->setViewChannels($viewChannels)
			->setPromoBanners($editor->getPromoBanners() ?? [])
			->setChannelsSort($channelsSort)
		;
	}

	public function getBindElement(): ?string
	{
		return $this->bindElement;
	}

	public function setBindElement(?string $bindElement): self
	{
		$this->bindElement = $bindElement;

		return $this;
	}

	/**
	 * @return ViewChannel[]
	 */
	public function getViewChannels(): array
	{
		return $this->viewChannels;
	}

	/**
	 * @param ViewChannel[] $viewChannels
	 */
	public function setViewChannels(array $viewChannels): self
	{
		$this->viewChannels = array_values(
			array_filter($viewChannels, static fn($ch): bool => $ch instanceof ViewChannel),
		);

		return $this;
	}

	/**
	 * @return PromoBanner[]
	 */
	public function getPromoBanners(): array
	{
		return $this->promoBanners;
	}

	/**
	 * @param PromoBanner[] $promoBanners
	 */
	public function setPromoBanners(array $promoBanners): self
	{
		$this->promoBanners = array_values(
			array_filter($promoBanners, static fn($b): bool => $b instanceof PromoBanner),
		);

		return $this;
	}

	/**
	 * @return ChannelPosition[]
	 */
	public function getChannelsSort(): array
	{
		return $this->channelsSort;
	}

	/**
	 * @param ChannelPosition[] $channelsSort
	 */
	public function setChannelsSort(array $channelsSort): self
	{
		$this->channelsSort = array_values(
			array_filter($channelsSort, static fn($s): bool => $s instanceof ChannelPosition),
		);

		return $this;
	}

	public function jsonSerialize(): array
	{
		return [
			'bindElement' => $this->bindElement,
			'channels' => $this->viewChannels,
			'promoBanners' => $this->promoBanners,
			'channelsSort' => $this->channelsSort,
		];
	}
}
