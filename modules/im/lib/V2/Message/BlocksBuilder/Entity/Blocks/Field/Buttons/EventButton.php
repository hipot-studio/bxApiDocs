<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

class EventButton extends Button
{
	protected string $actionId;
	protected ?array $actionParams;

	protected function __construct(array $elementData)
	{
		parent::__construct($elementData);
		$this->actionId = $elementData['actionId'];
		$this->actionParams = $elementData['actionParams'] ?? null;
	}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->getType(),
			'title' => $this->title,
			'design' => $this->design,
			'actionId' => $this->actionId,
			'actionParams' => $this->actionParams,
		];
	}

	public function toArray(): array
	{
		return [
			'type' => $this->getType(),
			'title' => $this->title,
			'design' => $this->design,
			'actionId' => $this->actionId,
			'actionParams' => $this->actionParams,
		];
	}

	public function getType(): string
	{
		return Type::EventButton->value;
	}
}
