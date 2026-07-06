<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List;

class Fold implements \JsonSerializable
{
	protected bool $isOpened;
	protected string $title;

	private function __construct(array $blockData)
	{
		$this->isOpened = $blockData['isOpened'] ?? false;
		$this->title = $blockData['title'];
	}

	public static function create(array $blockData): self
	{
		return new self($blockData);
	}

	public function getPayloadText(): ?string
	{
		return null;
	}

	public function jsonSerialize(): array
	{
		return [
			'isOpened' => $this->isOpened,
			'title' => \Bitrix\Im\Text::parse($this->title),
		];
	}

	public function toArray(): array
	{
		return [
			'isOpened' => $this->isOpened,
			'title' => $this->title,
		];
	}
}
