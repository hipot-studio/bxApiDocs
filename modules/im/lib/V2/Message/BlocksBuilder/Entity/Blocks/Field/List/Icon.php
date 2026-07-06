<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List;

class Icon implements \JsonSerializable
{
	protected ?string $type;
	protected ?string $color;
	protected bool $isOrdered;

	private function __construct(array $blockData)
	{
		$this->type = $blockData['type'] ?? null;
		$this->color = $blockData['color'] ?? null;
		$this->isOrdered = $blockData['isOrdered'] ?? false;
	}

	public static function create(array $blockData, bool $isOrdered = false): self
	{
		$blockData['isOrdered'] = $isOrdered;

		return new self($blockData);
	}

	public function getPayloadText(): ?string
	{
		return null;
	}

	public function jsonSerialize(): array
	{
		if ($this->isOrdered)
		{
			return ['color' => $this->color];
		}

		return [
			'type' => $this->type,
			'color' => $this->color,
		];
	}

	public function toArray(): array
	{
		if ($this->isOrdered)
		{
			return ['color' => $this->color];
		}

		return [
			'type' => $this->type,
			'color' => $this->color,
		];
	}
}
