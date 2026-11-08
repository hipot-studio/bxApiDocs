<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields;

class Option implements \JsonSerializable
{
	protected string $id;
	protected string $label;

	public function __construct(string $id, string $label)
	{
		$this->id = $id;
		$this->label = $label;
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'label' => \Bitrix\Im\Text::parse($this->label),
		];
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'label' => $this->label,
		];
	}
}
