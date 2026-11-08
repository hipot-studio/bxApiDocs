<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

class RequestButton extends Button
{
	protected string $actionId;
	protected ?array $actionParams;
	protected bool $disabled;

	protected function __construct(array $elementData)
	{
		parent::__construct($elementData);
		$this->actionId = $elementData['actionId'];
		$this->actionParams = $elementData['actionParams'] ?? null;
		$this->disabled = $elementData['disabled'] ?? false;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->getType(),
			'title' => $this->title,
			'design' => $this->design,
			'actionId' => $this->actionId,
			'actionParams' => $this->actionParams,
			'disabled' => $this->disabled,
		];
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->getType(),
			'title' => $this->title,
			'design' => $this->design,
			'actionId' => $this->actionId,
			'actionParams' => $this->actionParams,
			'disabled' => $this->disabled,
		];
	}

	public function getType(): string
	{
		return Type::RequestButton->value;
	}

	public function isDisabled(): bool
	{
		return $this->disabled;
	}

	public function getActionId(): string
	{
		return $this->actionId;
	}
}
