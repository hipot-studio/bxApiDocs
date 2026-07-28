<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\ChannelSelector;

use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel as EditorViewChannel;
use Bitrix\MessageService\Public\UI\MessageEditor\ViewChannel\Appearance;

/**
 * A view model for Channel for channel selector
 */
final readonly class ViewChannel implements \JsonSerializable
{
	public function __construct(
		private string $id,
		private Appearance $appearance,
	)
	{
	}

	public static function fromEditorViewChannel(EditorViewChannel $editorViewChannel): self
	{
		return new self(
			$editorViewChannel->getId(),
			$editorViewChannel->getAppearance(),
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

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'appearance' => $this->appearance,
		];
	}
}
