<?php

namespace Bitrix\Crm\Badge;

class ValueItemOptions
{
	public const TEXT_COLOR_SUCCESS = '#76950b';
	public const TEXT_COLOR_FAILURE = '#dd4e5f';
	public const TEXT_COLOR_WARNING = '#755c18';
	public const TEXT_COLOR_PRIMARY = '#1e8ec2';
	public const TEXT_COLOR_SECONDARY = '#525c69';
	public const TEXT_COLOR_LAVENDER = 'rgba(142, 82, 236, 1)';

	public const BG_COLOR_SUCCESS = '#e9f6d6';
	public const BG_COLOR_FAILURE = '#f3d5d3';
	public const BG_COLOR_WARNING = '#ebe997';
	public const BG_COLOR_PRIMARY = '#e1f3f9';
	public const BG_COLOR_SECONDARY = '#eaebed';
	public const BG_COLOR_LAVENDER = 'rgba(231, 216, 250, 1)';

	public const STYLE_TINTED = 'tinted';
	public const STYLE_TINTED_SUCCESS = 'tintedSuccess';
	public const STYLE_TINTED_WARNING = 'tintedWarning';
	public const STYLE_TINTED_ALERT = 'tintedAlert';
	public const STYLE_TINTED_VIOLET = 'tintedViolet';
	public const STYLE_TINTED_NO_ACCENT = 'tintedNoAccent';

	private const BACKGROUND_TO_STYLE_MAP = [
		self::BG_COLOR_SUCCESS => self::STYLE_TINTED_SUCCESS,
		self::BG_COLOR_FAILURE => self::STYLE_TINTED_ALERT,
		self::BG_COLOR_WARNING => self::STYLE_TINTED_WARNING,
		self::BG_COLOR_PRIMARY => self::STYLE_TINTED,
		self::BG_COLOR_SECONDARY => self::STYLE_TINTED_NO_ACCENT,
		self::BG_COLOR_LAVENDER => self::STYLE_TINTED_VIOLET,
	];

	public static function resolveStyleByBackgroundColor(string $backgroundColor): string
	{
		return self::BACKGROUND_TO_STYLE_MAP[$backgroundColor] ?? self::STYLE_TINTED_NO_ACCENT;
	}

	public static function isAllowedStyle(string $style): bool
	{
		return in_array($style, [
			self::STYLE_TINTED,
			self::STYLE_TINTED_SUCCESS,
			self::STYLE_TINTED_WARNING,
			self::STYLE_TINTED_ALERT,
			self::STYLE_TINTED_VIOLET,
			self::STYLE_TINTED_NO_ACCENT,
		], true);
	}

	public static function ensureValidStyle(?string $style): string
	{
		return ($style !== null && self::isAllowedStyle($style))
			? $style
			: self::STYLE_TINTED_NO_ACCENT;
	}
}
