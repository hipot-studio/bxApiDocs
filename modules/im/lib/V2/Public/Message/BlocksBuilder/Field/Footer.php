<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Field;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\Spacing;
use Bitrix\Im\V2\Public\Message\BlocksBuilder\Field\Footer\FooterRow;

class Footer extends AbstractField
{
	protected array $rows = [];
	protected ?Spacing $spacing = null;

	public function addRow(FooterRow $row): self
	{
		$this->rows[] = $row->jsonSerialize();

		return $this;
	}

	/**
	 * The gap between the content of the block and the footer. It lives on the footer here but
	 * travels beside it on the wire: the footer itself is an array of rows, and a client reading it
	 * must keep finding rows there.
	 */
	public function setSpacing(Spacing $spacing): self
	{
		$this->spacing = $spacing;

		return $this;
	}

	public function getSpacing(): ?Spacing
	{
		return $this->spacing;
	}

	public function jsonSerialize(): array
	{
		return $this->rows;
	}

	public static function create(): self
	{
		return new self();
	}
}
