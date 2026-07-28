<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor;

/**
 * Scene is a concrete placement of the message editor, e.g. details tab scene and grid scene.
 * Each scene has its own preferences, e.g. channels sort is different in each scene.
 */
abstract class Scene implements \JsonSerializable
{
	abstract public function getId(): string;

	/**
	 * Scene can exclude some view channels. They will not be shown in the editor.
	 *
	 * @param ViewChannel[] $viewChannels
	 * @param Context $context
	 *
	 * @return ViewChannel[]
	 */
	public function filterViewChannels(array $viewChannels, Context $context): array
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
