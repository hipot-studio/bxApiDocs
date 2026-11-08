<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Design;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Type;

class RequestButton extends AbstractButton
{
	protected function __construct(
		protected readonly string $title,
		protected readonly string $actionId,
		protected readonly ?Design $design = null,
		protected readonly ?array $actionParams = null,
		protected readonly bool $disabled = false,
		protected readonly ?string $id = null,
	)
	{}

	public static function create(
		string $title,
		string $actionId,
		?Design $design = null,
		?array $actionParams = null,
		bool $disabled = false,
		?string $id = null
	): self
	{
		return new self($title, $actionId, $design, $actionParams, $disabled, $id);
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => Type::RequestButton->value,
			'title' => $this->title,
			'design' => $this->design?->value,
			'actionId' => $this->actionId,
			'actionParams' => $this->actionParams,
			'disabled' => $this->disabled,
		];
	}
}
