<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\List;

class Element implements \JsonSerializable
{
	protected string $text;

	private function __construct(array $elementData)
	{
		$this->text = $elementData['text'];
	}

	public static function create(array $elementData): self
	{
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
		];
	}
}
