<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Design;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Type;

class LinkButton extends AbstractButton
{
	protected function __construct(
		protected readonly string $title,
		protected readonly string $url,
		protected readonly ?Design $design = null,
	)
	{}

	public function jsonSerialize(): array
	{
		return [
			'type' => $this->getType(),
			'title' => $this->title,
			'design' => $this->design?->value,
			'url' => $this->url,
		];
	}

	public static function create(
		string $title,
		string $url,
		?Design $design = null,
	): self
	{
		return new self($title, $url, $design);
	}

	protected function getType(): string
	{
		return Type::LinkButton->value;
	}
}
