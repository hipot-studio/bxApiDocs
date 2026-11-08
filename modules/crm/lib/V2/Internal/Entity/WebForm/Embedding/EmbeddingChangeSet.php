<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

use Bitrix\Main\ArgumentException;

/**
 * Immutable set of changes of the embedding settings of one CRM form: one value per explicitly
 * specified writable path.
 *
 * A path absent from the set is not changed at all, while a specified path holding null clears the
 * setting it points to. The paths are the ones the read of the embedding publishes - a setting of a
 * variant is addressed the way the answer of the read carries it - so one setting has one public
 * name on both directions.
 *
 * The form itself has a change set of its own, and this one is not part of it: the embedding is
 * written by its own method, and the writable paths of the form carry no section for it.
 *
 * What the read publishes and this contract does not carry is read-only: the embed code of a
 * variant, its preview link, the enumerations of allowed values, the activity of the form, the
 * readiness of its resources, its public link - and every path under a variant that has no settings
 * at all.
 *
 * @internal
 */
final readonly class EmbeddingChangeSet
{
	/** A path standing above the settings: it is changed through its own properties. */
	public const KIND_OBJECT = 'object';
	public const KIND_STRING = 'string';
	public const KIND_FLAG = 'flag';
	public const KIND_INTEGER = 'integer';

	/** The property a published variant carries its settings in. */
	private const SETTINGS = 'settings';

	private const PATH_SEPARATOR = '.';

	/**
	 * Length of the caption of the button the form is opened by. The settings live in the serialized
	 * column the whole tree of the settings of the form shares, and that tree is bounded as a whole
	 * and not per setting, so a caption bounded by nothing is a way to lose every neighbouring
	 * setting at once. The bound is the one the storage of the form applies to a caption of its own:
	 * `BUTTON_CAPTION` of `b_crm_webform` is a `varchar(255)`.
	 */
	private const BUTTON_TEXT_MAX_LENGTH = 255;

	/**
	 * Length of the decoration of that button. The wizard of the embedding offers three values of it
	 * - none, `dotted` and `solid` - and the form dictionary publishes no list to check it against,
	 * so the bound is a length: the one the storage of the form applies to a short code of its own
	 * (`DUPLICATE_MODE` of `b_crm_webform` is a `varchar(20)`).
	 */
	private const BUTTON_DECORATION_MAX_LENGTH = 20;

	/**
	 * Length of a colour of that button. The colour picker of the wizard writes a hex colour with the
	 * opacity appended to it - nine characters - and the two colour columns of the form itself
	 * (`BUTTON_COLOR_FONT`, `BUTTON_COLOR_BG`) are `varchar(10)`.
	 */
	private const BUTTON_COLOR_MAX_LENGTH = 10;

	/**
	 * Form of a colour of that button: the hex the colour picker of the wizard writes, with the
	 * opacity appended to it or without it. A length alone is no bound for a colour - the published
	 * package composes the hover rule of the button as CSS text and appends the stored value to it
	 * as it is, so a value of another form is a way to write rules of one's own into every page the
	 * form is placed on.
	 */
	private const COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D';

	/**
	 * Settings of every variant that has them, keyed by the variant and by the path of the setting
	 * inside the stored block of that variant. The public path of a setting is the same path under
	 * the settings of the variant, so the correspondence of a public name and a key of the storage
	 * is written down once: `click` plus `button.color.textHover` is read at
	 * `click.settings.button.color.textHover` and stored at `VIEWS.click.button.color.textHover`.
	 *
	 * A setting the form dictionary publishes allowed values for names the enumeration it is checked
	 * against; the mode flags, the caption, the decoration and the colours have none. A string with
	 * no enumeration behind it names the length it is bounded by instead - the values of an
	 * enumerated one are already as short as the list it is checked against - and a colour names the
	 * form it is accepted in on top of that length.
	 *
	 * The delay belongs to the variant shown by time and the button to the variant shown on click:
	 * a variant carries its own settings and nothing else.
	 */
	private const WRITABLE_BY_VARIANT = [
		EmbeddingVariant::CLICK => [
			'type' => ['kind' => self::KIND_STRING, 'enumeration' => ViewOptions::ENUMERATION_TYPE],
			'position' => ['kind' => self::KIND_STRING, 'enumeration' => ViewOptions::ENUMERATION_POSITION],
			'vertical' => ['kind' => self::KIND_STRING, 'enumeration' => ViewOptions::ENUMERATION_VERTICAL],
			'button.use' => ['kind' => self::KIND_FLAG, 'enumeration' => null],
			'button.text' => [
				'kind' => self::KIND_STRING,
				'enumeration' => null,
				'maxLength' => self::BUTTON_TEXT_MAX_LENGTH,
			],
			'button.font' => ['kind' => self::KIND_STRING, 'enumeration' => ViewOptions::ENUMERATION_BUTTON_FONT],
			'button.align' => ['kind' => self::KIND_STRING, 'enumeration' => ViewOptions::ENUMERATION_BUTTON_ALIGN],
			'button.plain' => ['kind' => self::KIND_FLAG, 'enumeration' => null],
			'button.rounded' => ['kind' => self::KIND_FLAG, 'enumeration' => null],
			'button.outlined' => ['kind' => self::KIND_FLAG, 'enumeration' => null],
			'button.decoration' => [
				'kind' => self::KIND_STRING,
				'enumeration' => null,
				'maxLength' => self::BUTTON_DECORATION_MAX_LENGTH,
			],
			'button.color.text' => [
				'kind' => self::KIND_STRING,
				'enumeration' => null,
				'maxLength' => self::BUTTON_COLOR_MAX_LENGTH,
				'pattern' => self::COLOR_PATTERN,
			],
			'button.color.textHover' => [
				'kind' => self::KIND_STRING,
				'enumeration' => null,
				'maxLength' => self::BUTTON_COLOR_MAX_LENGTH,
				'pattern' => self::COLOR_PATTERN,
			],
			'button.color.background' => [
				'kind' => self::KIND_STRING,
				'enumeration' => null,
				'maxLength' => self::BUTTON_COLOR_MAX_LENGTH,
				'pattern' => self::COLOR_PATTERN,
			],
			'button.color.backgroundHover' => [
				'kind' => self::KIND_STRING,
				'enumeration' => null,
				'maxLength' => self::BUTTON_COLOR_MAX_LENGTH,
				'pattern' => self::COLOR_PATTERN,
			],
		],
		EmbeddingVariant::AUTO => [
			'type' => ['kind' => self::KIND_STRING, 'enumeration' => ViewOptions::ENUMERATION_TYPE],
			'position' => ['kind' => self::KIND_STRING, 'enumeration' => ViewOptions::ENUMERATION_POSITION],
			'vertical' => ['kind' => self::KIND_STRING, 'enumeration' => ViewOptions::ENUMERATION_VERTICAL],
			'delay' => ['kind' => self::KIND_INTEGER, 'enumeration' => ViewOptions::ENUMERATION_DELAY],
		],
	];

	/** @var array<string, mixed> */
	private array $values;

	/**
	 * @param array<string, mixed> $valuesByPath
	 * @throws ArgumentException when a path is outside this contract or a value breaks its kind
	 */
	public function __construct(array $valuesByPath = [])
	{
		$normalized = [];
		foreach ($valuesByPath as $path => $value)
		{
			$path = (string)$path;
			$normalized[$path] = self::normalizeValue($path, $value);
		}

		$this->values = $normalized;
	}

	public function isEmpty(): bool
	{
		return $this->values === [];
	}

	/**
	 * Paths specified by this change set, in the order they were specified.
	 *
	 * @return string[]
	 */
	public function paths(): array
	{
		return array_keys($this->values);
	}

	/**
	 * Tells whether the path itself is specified. Null value of a specified path means clearing.
	 */
	public function has(string $path): bool
	{
		return array_key_exists($path, $this->values);
	}

	/**
	 * Value of a specified path.
	 *
	 * @throws ArgumentException when the path is not specified: use has() to tell the states apart
	 */
	public function get(string $path): mixed
	{
		if (!array_key_exists($path, $this->values))
		{
			throw new ArgumentException(sprintf('Embedding change path `%s` is not specified', $path), 'path');
		}

		return $this->values[$path];
	}

	/**
	 * The write of the stored block of the variants: values in the form the storage holds them,
	 * keyed by the path inside that block. The sub key of the settings column the block itself lives
	 * in belongs to the storage and is not part of these paths.
	 *
	 * The storage holds a flag as the character `1` or `0`: that is what both editors write and what
	 * the public bundle of the form compares against, so a flag stored as anything else would be a
	 * setting nothing reads. Clearing a setting stores nothing in its place - the absence of a value
	 * is what every reader of the block already falls back on.
	 *
	 * @return array<string, mixed>
	 */
	public function storedWrites(): array
	{
		$writes = [];
		foreach ($this->values as $path => $value)
		{
			$declaration = self::declarationOf((string)$path);
			$storedPath = $declaration['variant'] . self::PATH_SEPARATOR . $declaration['leaf'];
			$writes[$storedPath] = self::toStored((string)$declaration['kind'], $value);
		}

		return $writes;
	}

	/**
	 * The kind of a path of this contract: the kind of a setting, or an object standing above the
	 * settings - a variant, its settings, its button, the colours of that button.
	 *
	 * @throws ArgumentException when the path is outside this contract
	 */
	public static function kindOf(string $path): string
	{
		$declaration = self::findDeclaration($path);
		if ($declaration !== null)
		{
			return (string)$declaration['kind'];
		}

		if (self::carriesSettings($path))
		{
			return self::KIND_OBJECT;
		}

		throw new ArgumentException(sprintf('Unknown embedding change path `%s`', $path), 'path');
	}

	/** Tells whether the path is a setting this contract writes. */
	public static function isWritablePath(string $path): bool
	{
		return self::findDeclaration($path) !== null;
	}

	/**
	 * Tells whether anything below the path is written: a variant with settings, the settings
	 * themselves, or an object standing inside them.
	 */
	public static function carriesSettings(string $path): bool
	{
		$prefix = $path . self::PATH_SEPARATOR;
		foreach (self::writablePaths() as $writablePath)
		{
			if (str_starts_with($writablePath, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Paths of every setting this contract writes.
	 *
	 * @return string[]
	 */
	public static function writablePaths(): array
	{
		$paths = [];
		foreach (self::WRITABLE_BY_VARIANT as $variant => $settings)
		{
			foreach (array_keys($settings) as $leaf)
			{
				$paths[] = self::pathOf((string)$variant, (string)$leaf);
			}
		}

		return $paths;
	}

	/**
	 * The enumeration the value of the path is checked against, or null for a setting the form
	 * dictionary publishes no allowed values for.
	 *
	 * @throws ArgumentException when the path is no setting of this contract
	 */
	public static function enumerationOf(string $path): ?string
	{
		$enumeration = self::declarationOf($path)['enumeration'];

		return $enumeration === null ? null : (string)$enumeration;
	}

	/**
	 * The length the value of the path is bounded by, or null for a setting carrying no bound of its
	 * own: an enumerated one is bounded by the list it is checked against, and a flag and a number
	 * have no length at all.
	 *
	 * @throws ArgumentException when the path is no setting of this contract
	 */
	public static function maxLengthOf(string $path): ?int
	{
		$maxLength = self::declarationOf($path)['maxLength'] ?? null;

		return $maxLength === null ? null : (int)$maxLength;
	}

	/**
	 * The form the value of the path is accepted in, or null for a setting whose value is accepted
	 * in any form its kind allows.
	 *
	 * @throws ArgumentException when the path is no setting of this contract
	 */
	public static function patternOf(string $path): ?string
	{
		$pattern = self::declarationOf($path)['pattern'] ?? null;

		return $pattern === null ? null : (string)$pattern;
	}

	/**
	 * The variant the setting belongs to.
	 *
	 * @throws ArgumentException when the path is no setting of this contract
	 */
	public static function variantOf(string $path): string
	{
		return (string)self::declarationOf($path)['variant'];
	}

	/** Tells whether the variant has settings of its own at all. */
	public static function hasSettings(string $variant): bool
	{
		return isset(self::WRITABLE_BY_VARIANT[$variant]);
	}

	/**
	 * @throws ArgumentException
	 */
	private static function normalizeValue(string $path, mixed $value): mixed
	{
		$kind = self::kindOf($path);
		if ($kind === self::KIND_OBJECT)
		{
			throw new ArgumentException(
				sprintf('Embedding path `%s` is changed through its own settings, not as a whole', $path),
				'path',
			);
		}

		if ($value === null)
		{
			return null;
		}

		$isKnownValue = match ($kind)
		{
			self::KIND_STRING => is_string($value),
			self::KIND_FLAG => is_bool($value),
			self::KIND_INTEGER => is_int($value),
		};
		if (!$isKnownValue)
		{
			throw new ArgumentException(
				sprintf('Embedding setting `%s` accepts a value of the kind `%s` or null', $path, $kind),
				'value',
			);
		}

		return $value;
	}

	private static function toStored(string $kind, mixed $value): mixed
	{
		if ($value === null)
		{
			return null;
		}

		return match ($kind)
		{
			self::KIND_FLAG => $value === true ? '1' : '0',
			self::KIND_INTEGER => (int)$value,
			self::KIND_STRING => (string)$value,
		};
	}

	/**
	 * @return array{kind: string, enumeration: string|null, maxLength?: int, pattern?: string, variant: string, leaf: string}
	 * @throws ArgumentException when the path is no setting of this contract
	 */
	private static function declarationOf(string $path): array
	{
		$declaration = self::findDeclaration($path);
		if ($declaration === null)
		{
			throw new ArgumentException(sprintf('Embedding path `%s` is no writable setting', $path), 'path');
		}

		return $declaration;
	}

	/**
	 * @return null|array{kind: string, enumeration: string|null, maxLength?: int, pattern?: string, variant: string, leaf: string}
	 */
	private static function findDeclaration(string $path): ?array
	{
		$parts = explode(self::PATH_SEPARATOR, $path, 3);
		if (count($parts) !== 3 || $parts[1] !== self::SETTINGS)
		{
			return null;
		}

		[$variant, , $leaf] = $parts;
		$declaration = self::WRITABLE_BY_VARIANT[$variant][$leaf] ?? null;

		return $declaration === null
			? null
			: $declaration + ['variant' => $variant, 'leaf' => $leaf]
		;
	}

	private static function pathOf(string $variant, string $leaf): string
	{
		return $variant . self::PATH_SEPARATOR . self::SETTINGS . self::PATH_SEPARATOR . $leaf;
	}
}
