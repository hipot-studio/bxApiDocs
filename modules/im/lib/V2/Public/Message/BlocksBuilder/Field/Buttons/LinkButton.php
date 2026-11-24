<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Design;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Type;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\ChipSize;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\List\IconType;

class LinkButton extends AbstractButton
{
	protected function __construct(
		protected readonly string $title,
		protected readonly string $url,
		protected readonly ?Design $design = null,
		protected readonly ?string $id = null,
		protected readonly ?array $forUsers = null,
		protected readonly ?bool $showTitle = null,
		protected readonly ?IconType $icon = null,
		protected readonly ?ChipSize $size = null,
	)
	{}

	public function jsonSerialize(): array
	{
		$button = [
			'id' => $this->id,
			'type' => $this->getType(),
			'title' => $this->title,
			'design' => $this->design?->value,
			'url' => $this->url,
		];

		// The key itself is the statement: present it only when the sender named the users.
		if ($this->forUsers !== null)
		{
			$button['forUsers'] = $this->forUsers;
		}

		if ($this->showTitle !== null)
		{
			$button['showTitle'] = $this->showTitle;
		}

		if ($this->icon !== null)
		{
			$button['icon'] = $this->icon->value;
		}

		// The size belongs to a chip, so it is named only when the sender asked for one: on a button
		// the key is turned down on send.
		if ($this->size !== null)
		{
			$button['size'] = $this->size->value;
		}

		return $button;
	}

	public static function create(
		string $title,
		string $url,
		?Design $design = null,
		?string $id = null,
		?array $forUsers = null,
		?bool $showTitle = null,
		?IconType $icon = null,
		?ChipSize $size = null
	): self
	{
		return new self($title, $url, $design, $id, $forUsers, $showTitle, $icon, $size);
	}

	protected function getType(): string
	{
		return Type::LinkButton->value;
	}
}
