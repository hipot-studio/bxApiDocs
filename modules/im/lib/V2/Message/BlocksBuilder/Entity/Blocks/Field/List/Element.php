<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List;

class Element implements \JsonSerializable
{
	protected string $text;
	protected ?string $color;
	protected Icon $icon;

	private function __construct(array $elementData)
	{
		$this->text = $elementData['text'];
		$this->color = $elementData['color'] ?? null;
		$this->icon = Icon::create($elementData['icon'] ?? [], $elementData['isOrdered']);
	}

	public static function create(array $elementData, bool $isOrdered): self
	{
		$elementData['isOrdered'] = $isOrdered;

		return new self($elementData);
	}

	public function getPayloadText(): ?string
	{
		return $this->text;
	}

	public function jsonSerialize(): array
	{
		return [
			'text' => \Bitrix\Im\Text::parse($this->text),
			'color' => $this->color,
			'icon' => $this->icon->jsonSerialize(),
		];
	}

	public function toArray(): array
	{
		return [
			'text' => $this->text,
			'color' => $this->color,
			'icon' => $this->icon->toArray(),
		];
	}
}
