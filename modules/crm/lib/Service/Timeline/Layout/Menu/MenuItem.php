<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Menu;

use Bitrix\Ui\Public\Enum\IconSet\Main as MainIcon;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

class MenuItem extends \Bitrix\Crm\Service\Timeline\Layout\Button
{
	protected ?string $icon = null;
	protected ?string $subtitle = null;
	protected ?string $design = null;
	protected ?bool $isSelected = null;
	protected ?bool $isLocked = null;
	protected ?BadgeText $badgeText = null;
	protected ?string $sectionCode = null;

	public function getIcon(): ?string
	{
		return $this->icon;
	}

	/**
	 * Sets a public `ui.icon-set` identifier for the menu item icon.
	 *
	 * @param string|Outline|MainIcon $icon Ready icon id or a public enum value.
	 */
	public function setIcon(string|Outline|MainIcon $icon): self
	{
		$this->icon = is_string($icon) ? $icon : $icon->value;

		return $this;
	}

	/**
	 * Returns secondary text displayed under the menu item title.
	 */
	public function getSubtitle(): ?string
	{
		return $this->subtitle;
	}

	/**
	 * Sets secondary text displayed under the menu item title.
	 */
	public function setSubtitle(?string $subtitle): self
	{
		$this->subtitle = $subtitle;

		return $this;
	}

	/**
	 * Returns the `ui.system.menu` item design value.
	 */
	public function getDesign(): ?string
	{
		return $this->design;
	}

	/**
	 * Sets the `ui.system.menu` item design value.
	 */
	public function setDesign(null|string|MenuItemDesign $design): self
	{
		$this->design = $design instanceof MenuItemDesign ? $design->value : $design;

		return $this;
	}

	/**
	 * Returns whether the item should be rendered as selected.
	 */
	public function getIsSelected(): ?bool
	{
		return $this->isSelected;
	}

	/**
	 * Marks the item as selected in `ui.system.menu`.
	 */
	public function setIsSelected(?bool $isSelected): self
	{
		$this->isSelected = $isSelected;

		return $this;
	}

	/**
	 * Returns whether the item should be rendered with a lock marker.
	 */
	public function getIsLocked(): ?bool
	{
		return $this->isLocked;
	}

	/**
	 * Marks the item as locked in `ui.system.menu`.
	 */
	public function setIsLocked(?bool $isLocked): self
	{
		$this->isLocked = $isLocked;

		return $this;
	}

	/**
	 * Returns badge text payload for the item.
	 */
	public function getBadgeText(): ?BadgeText
	{
		return $this->badgeText;
	}

	/**
	 * Sets badge text payload for the item.
	 */
	public function setBadgeText(?BadgeText $badgeText): self
	{
		$this->badgeText = $badgeText;

		return $this;
	}

	/**
	 * Returns the explicit section code for the item.
	 */
	public function getSectionCode(): ?string
	{
		return $this->sectionCode;
	}

	/**
	 * Assigns the item to an explicit menu section.
	 */
	public function setSectionCode(?string $sectionCode): self
	{
		$this->sectionCode = $sectionCode;

		return $this;
	}

	public function toArray(): array
	{
		return array_merge(
			parent::toArray(),
			[
				'icon' => $this->getIcon(),
				'subtitle' => $this->getSubtitle(),
				'design' => $this->getDesign(),
				'isSelected' => $this->getIsSelected(),
				'isLocked' => $this->getIsLocked(),
				'badgeText' => $this->getBadgeText(),
				'sectionCode' => $this->getSectionCode(),
			]
		);
	}
}
