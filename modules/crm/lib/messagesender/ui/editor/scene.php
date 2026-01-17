<?php

namespace Bitrix\Crm\MessageSender\UI\Editor;

abstract class Scene implements \JsonSerializable
{
	abstract public function getId(): string;

	/**
	 * Scene can exclude some view channels. They will not be shown in the editor.
	 *
	 * @param ViewChannel[] $viewChannels
	 *
	 * @return ViewChannel[]
	 */
	public function filterViewChannels(array $viewChannels): array
	{
		return $viewChannels;
	}

	/**
	 * Scene can exclude some content providers. They will not be used in the editor.
	 *
	 * @param ContentProvider[] $providers
	 *
	 * @return ContentProvider[]
	 */
	public function filterContentProviders(array $providers): array
	{
		return $providers;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
		];
	}
}
