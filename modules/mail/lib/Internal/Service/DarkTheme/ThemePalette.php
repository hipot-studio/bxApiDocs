<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Dark theme colors of the mobile application and the lightness bands they anchor.
 *
 * The values belong to the application, not to the server: the client sends the theme tokens with the
 * request. The palette only reports whether the input is usable - the decision to fall back to the original
 * body belongs to the public facade.
 *
 * A band is the lightness range a role is mapped into. Document surfaces never end up darker than the theme
 * surface, so an inverted white background lands on the application background instead of pure black, and
 * foreground roles never end up lighter than the theme token they belong to, so main text, secondary text and
 * quotes stay ordered the way the design system orders them.
 */
final class ThemePalette
{
	public const TOKEN_BG_CONTENT_PRIMARY = 'bgContentPrimary';
	public const TOKEN_BASE_1 = 'base1';
	public const TOKEN_BASE_2 = 'base2';
	public const TOKEN_BASE_3 = 'base3';
	public const TOKEN_ACCENT_MAIN_LINK = 'accentMainLink';
	public const TOKEN_BG_SEPARATOR_PRIMARY = 'bgSeparatorPrimary';

	public const ROLE_SURFACE = 'SURFACE';
	public const ROLE_TEXT = 'TEXT';
	public const ROLE_SECONDARY = 'SECONDARY';
	public const ROLE_QUOTE = 'QUOTE';
	public const ROLE_LINK = 'LINK';
	public const ROLE_BORDER = 'BORDER';

	public const PALETTE_KEY = 'palette';

	public const ERROR_INVALID_TOKEN = 'INVALID_PALETTE_TOKEN';

	private const TOKEN_PATTERN = '/^#[0-9a-f]{6}$/i';

	private const SURFACE_BAND_SPAN = 0.10;
	private const FOREGROUND_BAND_SPAN = 0.12;

	/**
	 * Secondary text and quotes descend less than main text: their anchors already sit below main text, so a
	 * wide descent pushes them into a lightness where they read as dull instead of merely quieter.
	 */
	private const MUTED_BAND_SPAN = 0.06;

	/** Links keep more of the author lightness than text: brand accents differ from each other by design. */
	private const ACCENT_BAND_SPAN = 0.25;

	/** An inverted saturated background must read as a surface, not as a wall of color. */
	private const SURFACE_MAX_SATURATION = 0.20;

	private const ROLE_ANCHORS = [
		self::ROLE_SURFACE => self::TOKEN_BG_CONTENT_PRIMARY,
		self::ROLE_TEXT => self::TOKEN_BASE_1,
		self::ROLE_SECONDARY => self::TOKEN_BASE_2,
		self::ROLE_QUOTE => self::TOKEN_BASE_3,
		self::ROLE_LINK => self::TOKEN_ACCENT_MAIN_LINK,
		self::ROLE_BORDER => self::TOKEN_BG_SEPARATOR_PRIMARY,
	];

	/**
	 * @param array<string, Color> $colors
	 */
	private function __construct(private readonly array $colors)
	{
	}

	/**
	 * Builds a palette out of the theme tokens sent by the client.
	 *
	 * @param array $tokens Token name to `#rrggbb` value.
	 * @return Result Data: the palette. Errors: one per token that is missing or malformed.
	 */
	public static function createFromTokens(array $tokens): Result
	{
		$result = new Result();
		$colors = [];

		// Every anchor token is required: a role without its anchor has no band to map into.
		foreach (self::ROLE_ANCHORS as $token)
		{
			$value = is_string($tokens[$token] ?? null) ? trim($tokens[$token]) : '';
			$color = preg_match(self::TOKEN_PATTERN, $value) === 1 ? Color::fromString($value) : null;

			if ($color === null)
			{
				$result->addError(
					new Error(
						sprintf('Palette token "%s" is missing or is not in #rrggbb form.', $token),
						self::ERROR_INVALID_TOKEN
					)
				);

				continue;
			}

			$colors[$token] = $color;
		}

		if (!$result->isSuccess())
		{
			return $result;
		}

		return $result->setData([self::PALETTE_KEY => new self($colors)]);
	}

	/**
	 * Returns the document surface: the target background of the body and the contrast base to use when the
	 * background actually behind an element is unknown.
	 *
	 * @return Color
	 */
	public function getSurface(): Color
	{
		return $this->colors[self::TOKEN_BG_CONTENT_PRIMARY];
	}

	/**
	 * Returns the theme text color: the foreground of a body whose root the recolor paints itself.
	 *
	 * @return Color
	 */
	public function getText(): Color
	{
		return $this->colors[self::TOKEN_BASE_1];
	}

	/**
	 * Returns the theme link color, used when an author link comes out indistinguishable from body text.
	 *
	 * @return Color
	 */
	public function getLinkAccent(): Color
	{
		return $this->colors[self::TOKEN_ACCENT_MAIN_LINK];
	}

	/**
	 * Returns the theme separator color, used when an author border cannot reach the required contrast.
	 *
	 * @return Color
	 */
	public function getSeparator(): Color
	{
		return $this->colors[self::TOKEN_BG_SEPARATOR_PRIMARY];
	}

	/**
	 * Returns the lightness band and the saturation cap of the role.
	 *
	 * @param string $role One of the ROLE_* constants.
	 * @return array{minL: float, maxL: float, maxS: float}
	 * @throws ArgumentException
	 */
	public function getBand(string $role): array
	{
		$anchor = self::ROLE_ANCHORS[$role] ?? null;
		if ($anchor === null)
		{
			throw new ArgumentException('Unknown color role.', 'role');
		}

		$lightness = $this->colors[$anchor]->toHsl()['l'];

		if ($role === self::ROLE_SURFACE)
		{
			return [
				'minL' => $lightness,
				'maxL' => min(1.0, $lightness + self::SURFACE_BAND_SPAN),
				'maxS' => self::SURFACE_MAX_SATURATION,
			];
		}

		$span = match ($role)
		{
			self::ROLE_LINK => self::ACCENT_BAND_SPAN,
			self::ROLE_SECONDARY, self::ROLE_QUOTE => self::MUTED_BAND_SPAN,
			default => self::FOREGROUND_BAND_SPAN,
		};

		return [
			'minL' => max(0.0, $lightness - $span),
			'maxL' => $lightness,
			'maxS' => 1.0,
		];
	}
}
