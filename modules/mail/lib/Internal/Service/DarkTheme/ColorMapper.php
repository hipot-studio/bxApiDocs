<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

/**
 * Translates a single message color into the dark theme.
 *
 * Lightness is inverted, hue is kept, so a green status stays green and a red warning stays red; the role
 * pulls the result into the lightness band of its theme token, and contrast to the background actually visible
 * behind the element is raised to the WCAG 2.1 AA requirement of the role.
 *
 * One instance serves one message: the instance memoizes translations and remembers whether any of them could
 * not reach the required contrast.
 */
final class ColorMapper
{
	public const REASON_CONTRAST_UNREACHABLE = 'CONTRAST_UNREACHABLE';

	private const LIGHTNESS_STEP = 0.02;

	private const CONTRAST_TARGET_NORMAL = 4.5;
	private const CONTRAST_TARGET_LARGE = 3.0;

	/**
	 * A background this dark by relative luminance is the author's own dark scheme and is kept as it is. The
	 * bound is luminance, not lightness: a desaturated dark card and a vivid brand accent can share a lightness,
	 * but only the first is a dark surface, and keeping a bright accent as a surface would leave the text on it
	 * short of the required contrast. It sits below the luminance where a saturated accent starts to appear,
	 * yet above the darkest surfaces the theme itself uses, so an author dark page and its cards are both kept.
	 */
	private const AUTHOR_DARK_MAX_LUMINANCE = 0.15;

	/**
	 * A background this saturated and this light is a brand accent, not a surface: a call to action the
	 * author drew on purpose, kept as it is so its identity survives the dark theme. The luminance floor sits at
	 * the light pivot of a color, so a kept accent is always light: the text on it is then darkened to reach the
	 * required contrast, which a dark color always can, so keeping the accent never leaves a translation short
	 * of contrast. Accents below the pivot stay inverted into a dark surface, as they did before, and a light
	 * tinted surface above the ceiling is darkened as any other light surface, so only a real accent is kept.
	 */
	private const ACCENT_MIN_LUMINANCE = 0.18;
	private const ACCENT_MAX_LUMINANCE = 0.55;
	private const ACCENT_MIN_SATURATION = 0.45;

	/**
	 * How a light surface is elevated once it sits inside another surface. The page - the outermost
	 * surface - is dropped to the theme surface, the darkest level, so it never competes with its content. A
	 * content surface is lifted above the page by a fixed step and then ordered within its band by the lightness
	 * of the author color, so a white card reads as raised over a grey page and a button reads apart from the
	 * row it sits on, instead of every light surface collapsing onto the one near-black the floor produced.
	 */
	private const SURFACE_CONTENT_ELEVATION = 0.06;
	private const SURFACE_CONTENT_SPAN = 0.14;

	/** An achromatic link cannot be told from body text unless the author underlined it, and many do not. */
	private const LINK_MIN_SATURATION = 0.15;

	private const MAX_CACHE_SIZE = 4096;

	/** @var array<string, string|null> */
	private array $cache = [];

	private bool $unreachableContrast = false;

	public function __construct(private readonly ThemePalette $palette)
	{
	}

	/**
	 * Translates a color declaration of a message.
	 *
	 * @param string $value Value as written in the message.
	 * @param string $role One of the ThemePalette::ROLE_* constants.
	 * @param Color|null $surface Background actually visible behind the element; the document surface of the
	 *                            palette is used when the element is unknown, as for style sheet rules.
	 * @param bool $largeText Whether the declaration paints large text, which needs 3:1 instead of 4.5:1.
	 * @param bool $elevated Whether a ThemePalette::ROLE_SURFACE value sits inside another surface.
	 * @param Color|null $authorBackdrop Background the author had under the declaration - what the reader sees
	 *                                   behind it in the light theme - or null when no ancestor declared one.
	 * @return string|null Value to write, or null when the declaration must stay as it is - because the notation
	 *                     is not one we rewrite, because the required contrast is unreachable over an author
	 *                     surface and the author value is the best answer left, or because it is unreachable
	 *                     over a surface of ours and the whole body is about to be rejected.
	 */
	public function mapCssValue(
		string $value,
		string $role,
		?Color $surface = null,
		bool $largeText = false,
		bool $elevated = false,
		?Color $authorBackdrop = null
	): ?string
	{
		$surface ??= $this->palette->getSurface();
		$elevated = $elevated && $role === ThemePalette::ROLE_SURFACE;

		$key = implode(
			"\x00",
			[
				strtolower(trim($value)),
				$role,
				$surface->toCssValue(),
				$largeText ? '1' : '0',
				$elevated ? '1' : '0',
				$authorBackdrop?->toCssValue() ?? '',
			]
		);

		if (array_key_exists($key, $this->cache))
		{
			return $this->cache[$key];
		}

		$color = Color::fromString($value);
		$mapped = $color === null
			? null
			: $this->map($color, $role, $surface, $largeText, $elevated, $authorBackdrop)
		;
		$cssValue = $mapped?->toCssValue();

		if (count($this->cache) < self::MAX_CACHE_SIZE)
		{
			$this->cache[$key] = $cssValue;
		}

		return $cssValue;
	}

	/**
	 * Translates a parsed color.
	 *
	 * @param Color $color
	 * @param string $role One of the ThemePalette::ROLE_* constants.
	 * @param Color|null $surface Background actually visible behind the element; the document surface of the
	 *                            palette by default and unused for ThemePalette::ROLE_SURFACE, which needs no
	 *                            contrast base.
	 * @param bool $largeText Whether the declaration paints large text.
	 * @param bool $elevated Whether a ThemePalette::ROLE_SURFACE color sits inside another surface.
	 * @param Color|null $authorBackdrop Background the author had under the declaration, or null when none was
	 *                                   declared anywhere above it.
	 * @return Color|null Null when the required contrast is unreachable: over an author surface that leaves the
	 *                    author color in place, over a surface of ours that rejects the body.
	 */
	public function map(
		Color $color,
		string $role,
		?Color $surface = null,
		bool $largeText = false,
		bool $elevated = false,
		?Color $authorBackdrop = null
	): ?Color
	{
		$surface ??= $this->palette->getSurface();
		$band = $this->palette->getBand($role);

		if ($role === ThemePalette::ROLE_SURFACE)
		{
			return $this->mapSurface($color, $color->toHsl(), $band, $elevated);
		}

		// A fully transparent foreground paints nothing, and nothing is what the reader has to go on seeing.
		// Flattening it against the author backdrop would hand hidden text an opaque color and then show it; and
		// the contrast ladder cannot move a color that renders as the surface at every step of it, so such a
		// value used to cost the whole message its recolor whenever no backdrop hid the defect.
		if ($color->alpha <= 0.0)
		{
			return null;
		}

		$color = $this->flattenTranslucentForeground($color, $role, $authorBackdrop);
		$hsl = $color->toHsl();

		$inverted = Color::fromHsl(
			$hsl['h'],
			min($hsl['s'], $band['maxS']),
			$this->clamp(1.0 - $hsl['l'], $band['minL'], $band['maxL']),
			$color->alpha
		);

		$target = $largeText || $role === ThemePalette::ROLE_BORDER
			? self::CONTRAST_TARGET_LARGE
			: self::CONTRAST_TARGET_NORMAL
		;

		$adjusted = $this->adjustContrast($inverted, $surface, $target);

		if ($adjusted === null)
		{
			// A border is not worth the whole message: use the theme separator instead.
			return $role === ThemePalette::ROLE_BORDER
				? $this->palette->getSeparator()->withAlpha($color->alpha)
				: $this->keepAuthorValueOrReject($surface, $authorBackdrop)
			;
		}

		if ($role === ThemePalette::ROLE_LINK && $this->isAchromatic($adjusted))
		{
			return $this->replaceLinkWithAccent($surface, $target, $color->alpha, $authorBackdrop);
		}

		return $adjusted;
	}

	/**
	 * Tells whether any translation of this message failed to reach the required contrast, which means the
	 * body has to be returned in its original theme (REASON_CONTRAST_UNREACHABLE).
	 *
	 * @return bool
	 */
	public function hasUnreachableContrast(): bool
	{
		return $this->unreachableContrast;
	}

	/**
	 * Returns the palette the translations are built against.
	 *
	 * @return ThemePalette
	 */
	public function getPalette(): ThemePalette
	{
		return $this->palette;
	}

	/**
	 * Maps a background into the dark theme.
	 *
	 * An author surface that is already dark is returned untouched: the author drew the dark scheme, and
	 * inverting it would fight the design and break the elevation the author built between page and cards
	 * unchanged. A light surface is mapped by elevation. The page - a surface with no surface above it -
	 * drops to the theme surface, the darkest level, so a card on it always has room to sit higher. A content
	 * surface is lifted above the page by a fixed step and then ordered within its band by the lightness of the
	 * author color, so that a whiter card reads as raised over a greyer page and neighbouring surfaces stay told
	 * apart instead of collapsing onto the near-black floor. Hue is kept and saturation is capped so an inverted
	 * saturated background still reads as a surface.
	 *
	 * @param Color $color
	 * @param array{h: float, s: float, l: float} $hsl
	 * @param array{minL: float, maxL: float, maxS: float} $band
	 * @param bool $elevated Whether the surface sits inside another surface, that is above the page.
	 * @return Color
	 */
	private function mapSurface(Color $color, array $hsl, array $band, bool $elevated): Color
	{
		if ($color->getRelativeLuminance() < self::AUTHOR_DARK_MAX_LUMINANCE || $this->isAccent($color, $hsl))
		{
			return $color;
		}

		$lightness = $elevated
			? $band['minL'] + self::SURFACE_CONTENT_ELEVATION + $hsl['l'] * self::SURFACE_CONTENT_SPAN
			: $band['minL']
		;

		return Color::fromHsl($hsl['h'], min($hsl['s'], $band['maxS']), min($lightness, 1.0), $color->alpha);
	}

	/**
	 * Tells whether a background is a bright brand accent that mapSurface keeps as it is.
	 *
	 * The element walk asks the same question of a surface it is about to recolor content against: a kept accent
	 * is the one light surface the content really sits on, so its subtree keeps the author colors and its label
	 * is paired with the accent instead of being demoted to a link color.
	 *
	 * @param Color $color
	 * @return bool
	 */
	public function isAccentSurface(Color $color): bool
	{
		return $this->isAccent($color, $color->toHsl());
	}

	/**
	 * Tells whether a background is a bright brand accent that is kept instead of inverted into a surface.
	 *
	 * @param Color $color
	 * @param array{h: float, s: float, l: float} $hsl
	 * @return bool
	 */
	private function isAccent(Color $color, array $hsl): bool
	{
		$luminance = $color->getRelativeLuminance();

		return $luminance >= self::ACCENT_MIN_LUMINANCE
			&& $luminance <= self::ACCENT_MAX_LUMINANCE
			&& $hsl['s'] >= self::ACCENT_MIN_SATURATION
		;
	}

	private function replaceLinkWithAccent(
		Color $surface,
		float $target,
		float $alpha,
		?Color $authorBackdrop
	): ?Color
	{
		$accent = $this->palette->getLinkAccent()->withAlpha($alpha);

		return $this->adjustContrast($accent, $surface, $target)
			?? $this->keepAuthorValueOrReject($surface, $authorBackdrop)
		;
	}

	/**
	 * Flattens a translucent foreground against the background the author had under it.
	 *
	 * A translucent color is not a color the reader sees: the browser mixes it with what lies behind, and in the
	 * light theme what lies behind is the author background. `rgba(0, 0, 0, 0.6)` on a white card renders as
	 * `#666666`, and `#666666` is the color the recolor has to translate. Translating the transparent value
	 * instead carries the alpha into the result, our own surface then shows through it a second time, and the
	 * contrast such a pair can reach is bounded by that surface however the color is chosen - which is how a
	 * muted note on a white card cost the whole message its recolor at a ceiling of 4.28:1.
	 *
	 * Only a foreground is flattened. A surface is the background itself, a border is not text, and a background
	 * nobody declared is not white by default - guessing one would translate against a color the author never
	 * wrote.
	 *
	 * The result carries no alpha of its own, and nothing here has to strip one: a backdrop is only ever
	 * reported once there is a known opaque color under the declaration, so compositing over it always returns
	 * an opaque color. Do not add an explicit reset - it would read as if the invariant were not there.
	 */
	private function flattenTranslucentForeground(Color $color, string $role, ?Color $authorBackdrop): Color
	{
		return $authorBackdrop !== null && $color->alpha < 1.0 && $role !== ThemePalette::ROLE_BORDER
			? $color->compositeOver($authorBackdrop)
			: $color
		;
	}

	private function adjustContrast(Color $color, Color $surface, float $target): ?Color
	{
		$hsl = $color->toHsl();
		$lightness = $hsl['l'];
		$step = $surface->isLight() ? -self::LIGHTNESS_STEP : self::LIGHTNESS_STEP;
		$result = $color;

		while ($result->compositeOver($surface)->getContrastRatio($surface) < $target)
		{
			$lightness += $step;
			if ($lightness < 0.0 || $lightness > 1.0)
			{
				return null;
			}

			$result = Color::fromHsl($hsl['h'], $hsl['s'], $lightness, $color->alpha);
		}

		return $result;
	}

	private function isAchromatic(Color $color): bool
	{
		return $color->toHsl()['s'] < self::LINK_MIN_SATURATION;
	}

	/**
	 * Answers a translation that cannot reach the required contrast.
	 *
	 * Over a surface of ours the pair is one the recolor assembled, so a foreground that does not read on it is
	 * a defect of the recolor and the whole body goes back to the author. Over a surface the recolor left as the
	 * author painted it there is a third answer: nothing here can be improved, so the author value stays. That
	 * pair - author foreground on author background - is exactly what the reader already sees in the light
	 * theme, so it is never worse than returning the whole message to the author because of it, and the target
	 * itself does not move: an author pair is kept, not declared to pass.
	 *
	 * Whether the surface is the author paint is answered by comparing it to the backdrop the author had: the
	 * two coincide exactly when the recolor neither replaced that paint nor mixed a color of its own into it.
	 * A second flag saying the same thing is how two answers to one question drift apart.
	 *
	 * Beware when the lightness ladder of adjustContrast() is repaired: it stops at 0.0 and 1.0 by a fixed step
	 * and so never tries the ends themselves, and once a translucent foreground is flattened this branch is the
	 * only place that answer surfaces. Repairing the ladder without revisiting this branch risks leaving it as
	 * the quiet cover for the defect instead of the decision it is meant to carry.
	 */
	private function keepAuthorValueOrReject(Color $surface, ?Color $authorBackdrop): null
	{
		if ($authorBackdrop !== null && $surface->equals($authorBackdrop))
		{
			return null;
		}

		$this->unreachableContrast = true;

		return null;
	}

	private function clamp(float $value, float $min, float $max): float
	{
		return min(max($value, $min), $max);
	}
}
