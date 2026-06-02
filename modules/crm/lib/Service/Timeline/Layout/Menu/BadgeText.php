<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Menu;

use Bitrix\Crm\Service\Timeline\Layout\Base;
use Bitrix\Main\Localization\Loc;

/**
 * Value object for the `badgeText` payload supported by `ui.system.menu`.
 */
class BadgeText extends Base
{
	public const COLOR_SUCCESS = 'var(--ui-color-accent-main-success)';

	protected string $title;
	protected ?string $color = null;

	public function __construct(string $title, ?string $color = null)
	{
		$this->title = $title;
		$this->color = $color;
	}

	/**
	 * Creates the default localized "new" badge.
	 */
	public static function createNew(): self
	{
		return new self(
			Loc::getMessage('CRM_TIMELINE_MENU_BADGE_NEW'),
			self::COLOR_SUCCESS,
		);
	}

	/**
	 * Creates the default localized "recommended" badge.
	 */
	public static function createRecommended(): self
	{
		return new self(
			Loc::getMessage('CRM_TIMELINE_MENU_BADGE_RECOMMENDED'),
			self::COLOR_SUCCESS,
		);
	}

	/**
	 * Returns badge caption text.
	 */
	public function getTitle(): string
	{
		return $this->title;
	}

	/**
	 * Returns badge color token.
	 */
	public function getColor(): ?string
	{
		return $this->color;
	}

	/**
	 * Overrides badge color token.
	 */
	public function setColor(string $color): self
	{
		$this->color = $color;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'title' => $this->title,
			'color' => $this->color,
		];
	}
}
