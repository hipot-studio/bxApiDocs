<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ActionBar;

use Bitrix\Crm\Service\Timeline\Layout\Base;
use Bitrix\Crm\Service\Timeline\Layout\Mixin\Actionable;

final class ActionBarItem extends Base
{
	use Actionable;

	public const SIZE_SM = 's';
	public const SIZE_XS = 'xs';

	public const DESIGN_FILLED = 'filled';
	public const DESIGN_TINTED = 'tinted';
	public const DESIGN_OUTLINE = 'outline';
	public const DESIGN_COPILOT = 'outline-copilot';
	public const DESIGN_DISABLED = 'disabled';

	private string $size = self::SIZE_SM;
	private string $design = self::DESIGN_OUTLINE;
	private string $text = '';
	private bool $isRounded = false;
	private bool $isDropdown = false;
	private bool $isLock = false;

	public function getSize(): string
	{
		return $this->size;
	}

	public function setSize(string $size): self
	{
		$this->size = $size;

		return $this;
	}

	public function getDesign(): string
	{
		return $this->design;
	}

	public function setDesign(string $design): self
	{
		$this->design = $design;

		return $this;
	}

	public function getText(): string
	{
		return $this->text;
	}

	public function setText(string $text): self
	{
		$this->text = $text;

		return $this;
	}

	public function getIsRounded(): bool
	{
		return $this->isRounded;
	}

	public function setIsRounded(bool $isRounded): self
	{
		$this->isRounded = $isRounded;

		return $this;
	}

	public function getIsDropdown(): bool
	{
		return $this->isDropdown;
	}

	public function setIsDropdown(bool $isDropdown): self
	{
		$this->isDropdown = $isDropdown;

		return $this;
	}

	public function getIsLock(): bool
	{
		return $this->isLock;
	}

	public function setIsLock(bool $isLock): self
	{
		$this->isLock = $isLock;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'size' => $this->getSize(),
			'design' => $this->getDesign(),
			'text' => $this->getText(),
			'rounded' => $this->getIsRounded(),
			'dropdown' => $this->getIsDropdown(),
			'lock' => $this->getIsLock(),
			'action' => $this->getAction(),
		];
	}
}
