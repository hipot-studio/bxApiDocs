<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Footer;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\Kind;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\AbstractField;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Buttons\AbstractButton;

class FooterRow extends AbstractField
{
	protected array $elements = [];

	public function addButton(AbstractButton $button): self
	{
		return $this->addElement($button, Kind::Button);
	}

	public function addChip(AbstractButton $button): self
	{
		return $this->addElement($button, Kind::Chip);
	}

	public function jsonSerialize(): array
	{
		return $this->elements;
	}

	public static function create(): self
	{
		return new self();
	}

	private function addElement(AbstractButton $button, Kind $kind): self
	{
		$this->elements[] = $button->jsonSerialize() + ['kind' => $kind->value];

		return $this;
	}
}
