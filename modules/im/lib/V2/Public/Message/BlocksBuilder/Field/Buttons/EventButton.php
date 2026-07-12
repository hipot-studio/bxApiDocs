<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Design;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Type;

class EventButton extends AbstractButton
{
	protected function __construct(
		protected readonly string $title,
		protected readonly string $actionId,
		protected readonly ?Design $design = null,
		protected readonly ?array $actionParams = null,
	)
	{}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->getType(),
			'title' => $this->title,
			'design' => $this->design?->value,
			'actionId' => $this->actionId,
			'actionParams' => $this->actionParams,
		];
	}

	public static function create(
		string $title,
		string $actionId,
		?Design $design = null,
		?array $actionParams = null
	): self
	{
		return new self($title, $actionId, $design, $actionParams);
	}

	protected function getType(): string
	{
		return Type::EventButton->value;
	}
}
