<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

/**
 * Immutable color of a single message declaration together with the color math the recolor needs.
 *
 * Only notations a body may safely be rewritten in are parsed: `#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb()`,
 * `rgba()`, `hsl()`, `hsla()` and CSS color names. Everything else - `currentColor`, `var()`, `color-mix()`,
 * gradients, `transparent` - gives null, and the caller leaves such a declaration untouched. A value is
 * always assembled from parsed components, so no string from the message reaches the result.
 */
final class Color
{
	/**
	 * Relative luminance where contrast to black equals contrast to white: above it a surface gains more
	 * contrast from darker content, below it from lighter content.
	 */
	private const LUMINANCE_PIVOT = 0.1791;

	private const CHANNEL_MAX = 255;

	/** Alphas closer than half a step of the channel are the same paint: both round to the same byte. */
	private const ALPHA_EPSILON = 0.5 / self::CHANNEL_MAX;

	private const NUMBER_PATTERN = '/^[-+]?(?:\d+\.?\d*|\.\d+)$/';
	private const PERCENTAGE_PATTERN = '/^([-+]?(?:\d+\.?\d*|\.\d+))%$/';
	private const HUE_PATTERN = '/^([-+]?(?:\d+\.?\d*|\.\d+))(?:deg)?$/';
	private const FUNCTION_PATTERN = '/^(rgba?|hsla?)\(([^()]*)\)$/';
	private const HEX_PATTERN = '/^[0-9a-f]+$/';
	private const BARE_HEX_PATTERN = '/^(?:[0-9a-f]{3}|[0-9a-f]{6})$/i';

	/**
	 * The two grammars a browser renders: the legacy one separates every component by a comma, the modern one
	 * separates the channels by whitespace and the alpha by a slash. A doubled or a mixed separator is a
	 * declaration the browser drops.
	 */
	private const LEGACY_COMPONENTS_PATTERN
		= '/^([^\s,\/]+)\s*,\s*([^\s,\/]+)\s*,\s*([^\s,\/]+)(?:\s*,\s*([^\s,\/]+))?$/';
	private const MODERN_COMPONENTS_PATTERN
		= '/^([^\s,\/]+)\s+([^\s,\/]+)\s+([^\s,\/]+)(?:\s*\/\s*([^\s,\/]+))?$/';

	private const NAMED_COLORS = [
		'aliceblue' => '#f0f8ff',
		'antiquewhite' => '#faebd7',
		'aqua' => '#00ffff',
		'aquamarine' => '#7fffd4',
		'azure' => '#f0ffff',
		'beige' => '#f5f5dc',
		'bisque' => '#ffe4c4',
		'black' => '#000000',
		'blanchedalmond' => '#ffebcd',
		'blue' => '#0000ff',
		'blueviolet' => '#8a2be2',
		'brown' => '#a52a2a',
		'burlywood' => '#deb887',
		'cadetblue' => '#5f9ea0',
		'chartreuse' => '#7fff00',
		'chocolate' => '#d2691e',
		'coral' => '#ff7f50',
		'cornflowerblue' => '#6495ed',
		'cornsilk' => '#fff8dc',
		'crimson' => '#dc143c',
		'cyan' => '#00ffff',
		'darkblue' => '#00008b',
		'darkcyan' => '#008b8b',
		'darkgoldenrod' => '#b8860b',
		'darkgray' => '#a9a9a9',
		'darkgreen' => '#006400',
		'darkgrey' => '#a9a9a9',
		'darkkhaki' => '#bdb76b',
		'darkmagenta' => '#8b008b',
		'darkolivegreen' => '#556b2f',
		'darkorange' => '#ff8c00',
		'darkorchid' => '#9932cc',
		'darkred' => '#8b0000',
		'darksalmon' => '#e9967a',
		'darkseagreen' => '#8fbc8f',
		'darkslateblue' => '#483d8b',
		'darkslategray' => '#2f4f4f',
		'darkslategrey' => '#2f4f4f',
		'darkturquoise' => '#00ced1',
		'darkviolet' => '#9400d3',
		'deeppink' => '#ff1493',
		'deepskyblue' => '#00bfff',
		'dimgray' => '#696969',
		'dimgrey' => '#696969',
		'dodgerblue' => '#1e90ff',
		'firebrick' => '#b22222',
		'floralwhite' => '#fffaf0',
		'forestgreen' => '#228b22',
		'fuchsia' => '#ff00ff',
		'gainsboro' => '#dcdcdc',
		'ghostwhite' => '#f8f8ff',
		'gold' => '#ffd700',
		'goldenrod' => '#daa520',
		'gray' => '#808080',
		'green' => '#008000',
		'greenyellow' => '#adff2f',
		'grey' => '#808080',
		'honeydew' => '#f0fff0',
		'hotpink' => '#ff69b4',
		'indianred' => '#cd5c5c',
		'indigo' => '#4b0082',
		'ivory' => '#fffff0',
		'khaki' => '#f0e68c',
		'lavender' => '#e6e6fa',
		'lavenderblush' => '#fff0f5',
		'lawngreen' => '#7cfc00',
		'lemonchiffon' => '#fffacd',
		'lightblue' => '#add8e6',
		'lightcoral' => '#f08080',
		'lightcyan' => '#e0ffff',
		'lightgoldenrodyellow' => '#fafad2',
		'lightgray' => '#d3d3d3',
		'lightgreen' => '#90ee90',
		'lightgrey' => '#d3d3d3',
		'lightpink' => '#ffb6c1',
		'lightsalmon' => '#ffa07a',
		'lightseagreen' => '#20b2aa',
		'lightskyblue' => '#87cefa',
		'lightslategray' => '#778899',
		'lightslategrey' => '#778899',
		'lightsteelblue' => '#b0c4de',
		'lightyellow' => '#ffffe0',
		'lime' => '#00ff00',
		'limegreen' => '#32cd32',
		'linen' => '#faf0e6',
		'magenta' => '#ff00ff',
		'maroon' => '#800000',
		'mediumaquamarine' => '#66cdaa',
		'mediumblue' => '#0000cd',
		'mediumorchid' => '#ba55d3',
		'mediumpurple' => '#9370db',
		'mediumseagreen' => '#3cb371',
		'mediumslateblue' => '#7b68ee',
		'mediumspringgreen' => '#00fa9a',
		'mediumturquoise' => '#48d1cc',
		'mediumvioletred' => '#c71585',
		'midnightblue' => '#191970',
		'mintcream' => '#f5fffa',
		'mistyrose' => '#ffe4e1',
		'moccasin' => '#ffe4b5',
		'navajowhite' => '#ffdead',
		'navy' => '#000080',
		'oldlace' => '#fdf5e6',
		'olive' => '#808000',
		'olivedrab' => '#6b8e23',
		'orange' => '#ffa500',
		'orangered' => '#ff4500',
		'orchid' => '#da70d6',
		'palegoldenrod' => '#eee8aa',
		'palegreen' => '#98fb98',
		'paleturquoise' => '#afeeee',
		'palevioletred' => '#db7093',
		'papayawhip' => '#ffefd5',
		'peachpuff' => '#ffdab9',
		'peru' => '#cd853f',
		'pink' => '#ffc0cb',
		'plum' => '#dda0dd',
		'powderblue' => '#b0e0e6',
		'purple' => '#800080',
		'rebeccapurple' => '#663399',
		'red' => '#ff0000',
		'rosybrown' => '#bc8f8f',
		'royalblue' => '#4169e1',
		'saddlebrown' => '#8b4513',
		'salmon' => '#fa8072',
		'sandybrown' => '#f4a460',
		'seagreen' => '#2e8b57',
		'seashell' => '#fff5ee',
		'sienna' => '#a0522d',
		'silver' => '#c0c0c0',
		'skyblue' => '#87ceeb',
		'slateblue' => '#6a5acd',
		'slategray' => '#708090',
		'slategrey' => '#708090',
		'snow' => '#fffafa',
		'springgreen' => '#00ff7f',
		'steelblue' => '#4682b4',
		'tan' => '#d2b48c',
		'teal' => '#008080',
		'thistle' => '#d8bfd8',
		'tomato' => '#ff6347',
		'turquoise' => '#40e0d0',
		'violet' => '#ee82ee',
		'wheat' => '#f5deb3',
		'white' => '#ffffff',
		'whitesmoke' => '#f5f5f5',
		'yellow' => '#ffff00',
		'yellowgreen' => '#9acd32',
	];

	/** @var array{h: float, s: float, l: float}|null */
	private ?array $hsl = null;

	private ?float $luminance = null;

	private function __construct(
		public readonly int $red,
		public readonly int $green,
		public readonly int $blue,
		public readonly float $alpha,
	)
	{
	}

	/**
	 * Parses a color declared in a message body.
	 *
	 * @param string $value Value as written in the message.
	 * @return self|null Null when the notation is not one the recolor may rewrite.
	 */
	public static function fromString(string $value): ?self
	{
		$value = strtolower(trim($value));

		if ($value === '')
		{
			return null;
		}

		if ($value[0] === '#')
		{
			return self::fromHexNotation($value);
		}

		if (str_contains($value, '('))
		{
			return self::fromFunctionalNotation($value);
		}

		return isset(self::NAMED_COLORS[$value])
			? self::fromHexNotation(self::NAMED_COLORS[$value])
			: null
		;
	}

	/**
	 * Returns the value of a legacy presentation attribute as a CSS value: `bgcolor` also accepts a hex value
	 * without the hash, which is not a notation a style declaration may be written in.
	 *
	 * @param string $value Value as written in the attribute.
	 * @return string
	 */
	public static function normalizeAttributeValue(string $value): string
	{
		$value = trim($value);

		return preg_match(self::BARE_HEX_PATTERN, $value) === 1 ? '#' . $value : $value;
	}

	/**
	 * Builds a color from channel values.
	 *
	 * @param int $red
	 * @param int $green
	 * @param int $blue
	 * @param float $alpha
	 * @return self
	 */
	public static function fromRgb(int $red, int $green, int $blue, float $alpha = 1.0): self
	{
		return new self(
			self::clampChannel($red),
			self::clampChannel($green),
			self::clampChannel($blue),
			self::clampUnit($alpha)
		);
	}

	/**
	 * Builds a color from hue in degrees, saturation and lightness in the 0..1 range.
	 *
	 * @param float $hue
	 * @param float $saturation
	 * @param float $lightness
	 * @param float $alpha
	 * @return self
	 */
	public static function fromHsl(float $hue, float $saturation, float $lightness, float $alpha = 1.0): self
	{
		$hue = fmod($hue, 360.0);
		if ($hue < 0.0)
		{
			$hue += 360.0;
		}

		$saturation = self::clampUnit($saturation);
		$lightness = self::clampUnit($lightness);

		$chroma = (1.0 - abs(2.0 * $lightness - 1.0)) * $saturation;
		$secondary = $chroma * (1.0 - abs(fmod($hue / 60.0, 2.0) - 1.0));
		$offset = $lightness - $chroma / 2.0;

		[$red, $green, $blue] = match ((int)floor($hue / 60.0) % 6)
		{
			0 => [$chroma, $secondary, 0.0],
			1 => [$secondary, $chroma, 0.0],
			2 => [0.0, $chroma, $secondary],
			3 => [0.0, $secondary, $chroma],
			4 => [$secondary, 0.0, $chroma],
			default => [$chroma, 0.0, $secondary],
		};

		$color = new self(
			self::toChannel($red + $offset),
			self::toChannel($green + $offset),
			self::toChannel($blue + $offset),
			self::clampUnit($alpha)
		);

		// Keeping the source components spares the iterative contrast adjustment an rgb roundtrip per step.
		$color->hsl = [
			'h' => $hue,
			's' => $saturation,
			'l' => $lightness,
		];

		return $color;
	}

	/**
	 * Returns the same color with another alpha channel.
	 *
	 * @param float $alpha
	 * @return self
	 */
	public function withAlpha(float $alpha): self
	{
		return new self($this->red, $this->green, $this->blue, self::clampUnit($alpha));
	}

	/**
	 * Returns the visible color produced when this color is painted over the background.
	 *
	 * @param self $background
	 * @return self
	 */
	public function compositeOver(self $background): self
	{
		$backgroundWeight = $background->alpha * (1.0 - $this->alpha);
		$alpha = $this->alpha + $backgroundWeight;

		if ($alpha <= 0.0)
		{
			return new self(0, 0, 0, 0.0);
		}

		return new self(
			self::clampChannel(
				(int)round(($this->red * $this->alpha + $background->red * $backgroundWeight) / $alpha)
			),
			self::clampChannel(
				(int)round(($this->green * $this->alpha + $background->green * $backgroundWeight) / $alpha)
			),
			self::clampChannel(
				(int)round(($this->blue * $this->alpha + $background->blue * $backgroundWeight) / $alpha)
			),
			self::clampUnit($alpha)
		);
	}

	/**
	 * Returns hue in degrees, saturation and lightness in the 0..1 range.
	 *
	 * @return array{h: float, s: float, l: float}
	 */
	public function toHsl(): array
	{
		$this->hsl ??= $this->calculateHsl();

		return $this->hsl;
	}

	/**
	 * Returns the WCAG relative luminance of the color.
	 *
	 * @return float
	 */
	public function getRelativeLuminance(): float
	{
		$this->luminance ??=
			0.2126 * self::toLinearChannel($this->red)
			+ 0.7152 * self::toLinearChannel($this->green)
			+ 0.0722 * self::toLinearChannel($this->blue)
		;

		return $this->luminance;
	}

	/**
	 * Returns the WCAG contrast ratio between the two colors, alpha aside.
	 *
	 * @param self $other
	 * @return float
	 */
	public function getContrastRatio(self $other): float
	{
		$first = $this->getRelativeLuminance();
		$second = $other->getRelativeLuminance();

		return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
	}

	/**
	 * Tells whether the two colors are the same paint, notation aside.
	 *
	 * @param self $other
	 * @return bool
	 */
	public function equals(self $other): bool
	{
		return $this->red === $other->red
			&& $this->green === $other->green
			&& $this->blue === $other->blue
			&& abs($this->alpha - $other->alpha) < self::ALPHA_EPSILON
		;
	}

	/**
	 * Tells whether content over this color gains more contrast by getting darker.
	 *
	 * @return bool
	 */
	public function isLight(): bool
	{
		return $this->getRelativeLuminance() > self::LUMINANCE_PIVOT;
	}

	/**
	 * Returns the color as a CSS value assembled from its own components.
	 *
	 * @return string
	 */
	public function toCssValue(): string
	{
		if ($this->alpha >= 1.0)
		{
			return sprintf('#%02x%02x%02x', $this->red, $this->green, $this->blue);
		}

		return sprintf('rgba(%d, %d, %d, %s)', $this->red, $this->green, $this->blue, $this->formatAlpha());
	}

	private static function fromHexNotation(string $value): ?self
	{
		$digits = substr($value, 1);
		if (preg_match(self::HEX_PATTERN, $digits) !== 1)
		{
			return null;
		}

		// The short forms write one digit per channel and the browser doubles it: `#abc` and `#abcd` are the
		// same colors as `#aabbcc` and `#aabbccdd`. Reading three digits and not four is what let `#0000` - a
		// foreground that paints nothing - pass for a value nobody reads, and the recolor then wrote a
		// foreground of its own behind it and showed text the author had hidden.
		$length = strlen($digits);
		if ($length === 3 || $length === 4)
		{
			$doubled = '';
			for ($index = 0; $index < $length; $index++)
			{
				$doubled .= $digits[$index] . $digits[$index];
			}

			$digits = $doubled;
		}

		$length = strlen($digits);
		if ($length !== 6 && $length !== 8)
		{
			return null;
		}

		$alpha = $length === 8 ? (int)hexdec(substr($digits, 6, 2)) / self::CHANNEL_MAX : 1.0;

		return new self(
			(int)hexdec(substr($digits, 0, 2)),
			(int)hexdec(substr($digits, 2, 2)),
			(int)hexdec(substr($digits, 4, 2)),
			$alpha
		);
	}

	private static function fromFunctionalNotation(string $value): ?self
	{
		if (preg_match(self::FUNCTION_PATTERN, $value, $matches) !== 1)
		{
			return null;
		}

		$notation = self::parseFunctionComponents(trim($matches[2]));
		if ($notation === null)
		{
			return null;
		}

		$components = $notation['components'];

		$alpha = count($components) === 4 ? self::parseAlpha($components[3]) : 1.0;
		if ($alpha === null)
		{
			return null;
		}

		return str_starts_with($matches[1], 'hsl')
			? self::fromHslComponents($components, $alpha)
			: self::fromRgbComponents($components, $alpha, $notation['isLegacy'])
		;
	}

	/**
	 * Splits the arguments of a color function, accepting only one of the two grammars a browser renders and
	 * telling which of them was written.
	 *
	 * A value the browser drops - `rgb(255,,0,,0)`, `rgb(255, 0 0)` - must not be rewritten: the author color
	 * never reached the reader, and replacing it with a valid one lights up a foreground or a background the
	 * message did not have.
	 *
	 * @param string $arguments
	 * @return array{components: array<int, string>, isLegacy: bool}|null
	 */
	private static function parseFunctionComponents(string $arguments): ?array
	{
		if (preg_match(self::LEGACY_COMPONENTS_PATTERN, $arguments, $matches) === 1)
		{
			array_shift($matches);

			return [
				'components' => $matches,
				'isLegacy' => true,
			];
		}

		if (preg_match(self::MODERN_COMPONENTS_PATTERN, $arguments, $matches) === 1)
		{
			array_shift($matches);

			return [
				'components' => $matches,
				'isLegacy' => false,
			];
		}

		return null;
	}

	/**
	 * @param array<int, string> $components
	 */
	private static function fromRgbComponents(array $components, float $alpha, bool $isLegacy): ?self
	{
		$channels = array_slice($components, 0, 3);

		if ($isLegacy && !self::hasUniformChannelUnits($channels))
		{
			return null;
		}

		$values = [];

		foreach ($channels as $component)
		{
			$channel = self::parseRgbChannel($component);
			if ($channel === null)
			{
				return null;
			}

			$values[] = $channel;
		}

		return new self($values[0], $values[1], $values[2], $alpha);
	}

	/**
	 * Tells whether the channels are written in one unit, as the legacy grammar requires: it takes three
	 * numbers or three percentages, and only the modern grammar mixes the two. A mixed legacy value is a
	 * declaration the view drops whole, so rewriting it would paint a color the message never showed.
	 *
	 * @param array<int, string> $channels
	 * @return bool
	 */
	private static function hasUniformChannelUnits(array $channels): bool
	{
		$percentages = 0;

		foreach ($channels as $channel)
		{
			$percentages += (int)str_ends_with($channel, '%');
		}

		return $percentages === 0 || $percentages === count($channels);
	}

	/**
	 * The legacy grammar of this function asks for a hue and two percentages, which is what both grammars are
	 * read by here, so the uniformity the legacy one requires needs no question of its own: a percentage hue
	 * or a unitless saturation is already a value this parser does not read.
	 *
	 * @param array<int, string> $components
	 */
	private static function fromHslComponents(array $components, float $alpha): ?self
	{
		$hue = preg_match(self::HUE_PATTERN, $components[0], $matches) === 1 ? (float)$matches[1] : null;
		$saturation = self::parsePercentage($components[1]);
		$lightness = self::parsePercentage($components[2]);

		if ($hue === null || $saturation === null || $lightness === null)
		{
			return null;
		}

		return self::fromHsl($hue, $saturation, $lightness, $alpha);
	}

	private static function parseRgbChannel(string $component): ?int
	{
		$percentage = self::parsePercentage($component);
		if ($percentage !== null)
		{
			return self::toChannel($percentage);
		}

		return preg_match(self::NUMBER_PATTERN, $component) === 1
			? self::clampChannel((int)round((float)$component))
			: null
		;
	}

	private static function parseAlpha(string $component): ?float
	{
		$percentage = self::parsePercentage($component);
		if ($percentage !== null)
		{
			return $percentage;
		}

		return preg_match(self::NUMBER_PATTERN, $component) === 1
			? self::clampUnit((float)$component)
			: null
		;
	}

	private static function parsePercentage(string $component): ?float
	{
		return preg_match(self::PERCENTAGE_PATTERN, $component, $matches) === 1
			? self::clampUnit((float)$matches[1] / 100.0)
			: null
		;
	}

	/**
	 * @return array{h: float, s: float, l: float}
	 */
	private function calculateHsl(): array
	{
		// Casts keep the channels float: an integer division result would fail the achromatic check below,
		// and black and white are exactly the channels that divide evenly.
		$red = (float)$this->red / self::CHANNEL_MAX;
		$green = (float)$this->green / self::CHANNEL_MAX;
		$blue = (float)$this->blue / self::CHANNEL_MAX;

		$max = max($red, $green, $blue);
		$min = min($red, $green, $blue);
		$lightness = ($max + $min) / 2.0;
		$delta = $max - $min;

		if ($delta === 0.0)
		{
			return [
				'h' => 0.0,
				's' => 0.0,
				'l' => $lightness,
			];
		}

		$hue = match ($max)
		{
			$red => 60.0 * fmod(($green - $blue) / $delta, 6.0),
			$green => 60.0 * (($blue - $red) / $delta + 2.0),
			default => 60.0 * (($red - $green) / $delta + 4.0),
		};

		return [
			'h' => $hue < 0.0 ? $hue + 360.0 : $hue,
			's' => self::clampUnit($delta / (1.0 - abs(2.0 * $lightness - 1.0))),
			'l' => $lightness,
		];
	}

	private function formatAlpha(): string
	{
		$alpha = rtrim(rtrim(number_format($this->alpha, 3, '.', ''), '0'), '.');

		return $alpha === '' ? '0' : $alpha;
	}

	private static function toLinearChannel(int $channel): float
	{
		$value = $channel / self::CHANNEL_MAX;

		return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
	}

	private static function toChannel(float $value): int
	{
		return (int)round(self::clampUnit($value) * self::CHANNEL_MAX);
	}

	private static function clampChannel(int $value): int
	{
		return min(max($value, 0), self::CHANNEL_MAX);
	}

	private static function clampUnit(float $value): float
	{
		return min(max($value, 0.0), 1.0);
	}
}
