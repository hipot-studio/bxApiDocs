<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

abstract class Button implements \JsonSerializable
{
	protected string $title;
	protected string $design;
	protected int $linePosition;

	protected function __construct(array $buttonData)
	{
		$this->linePosition = $buttonData['linePosition'];
		$this->title = $buttonData['title'];
		$this->design = $buttonData['design'] ?? Design::Filled->value;
	}

	public static function create(array $buttonData): ?self
	{
		return match ($buttonData['type'] ?? '')
		{
			Type::LinkButton->value => new LinkButton($buttonData),
			Type::EventButton->value => new EventButton($buttonData),
			default => null,
		};
	}

	public function getPayloadText(): ?string
	{
		return null;
	}

	public abstract function jsonSerialize(): array;

	public abstract function toArray(): array;

	public abstract function getType(): string;

	public function getLinePosition(): int
	{
		return $this->linePosition;
	}
}
