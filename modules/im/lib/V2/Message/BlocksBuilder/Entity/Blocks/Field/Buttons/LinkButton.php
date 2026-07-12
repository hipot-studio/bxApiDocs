<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

class LinkButton extends Button
{
	protected string $url;

	protected function __construct(array $elementData)
	{
		parent::__construct($elementData);
		$this->url = $elementData['url'];
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->getType(),
			'title' => $this->title,
			'design' => $this->design,
			'url' => $this->url,
		];
	}

	public function toArray(): array
	{
		return [
			'type' => $this->getType(),
			'title' => $this->title,
			'design' => $this->design,
			'url' => $this->url,
		];
	}

	public function getType(): string
	{
		return Type::LinkButton->value;
	}
}
