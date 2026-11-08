<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\ViewSettings;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormPath;
use Bitrix\Crm\WebForm\Form;
use InvalidArgumentException;

/**
 * The settings column of a CRM form: which path of the form lives in which sub key of the column,
 * how the column holds the value of that sub key, and how a write of one sub key leaves the rest of
 * the column alone.
 *
 * The column is a single serialized tree shared with the legacy editor, so writing one setting is a
 * read-merge-write cycle over it. Only the sent leaves are replaced: the neighbouring leaf of the
 * same sub key, the neighbouring sub keys and the keys this contract knows nothing about are stored
 * back as they were read.
 *
 * One declaration serves both directions. The kind of a leaf says how a stored value is read
 * outward and how an outward value is stored back, and the default of a leaf is both the value the
 * read answers while the column holds nothing usable and the value a form is created with, so that
 * reading a form and writing it back changes nothing.
 *
 * @internal
 */
final class FormSettingsColumn
{
	public const COLUMN = 'FORM_SETTINGS';

	/** The sub key the settings of the embedding variants live in. */
	public const VIEWS = 'VIEWS';

	/** The sub key the funnel of the deal lives in. */
	public const DEAL_CATEGORY = 'DEAL_CATEGORY';

	/**
	 * Bytes the column holds. It is a `text` column carrying the whole tree serialized, so this
	 * bounds the tree as a whole and no single setting of it.
	 *
	 * A database that is not in a strict mode answers a value that does not fit with a warning and
	 * stores as much of it as fits, and a serialized tree cut short does not read back at all: every
	 * setting the column holds is lost at once, the design of the form and its embedding variants
	 * included. That is why a tree that outgrew the column is refused instead of written.
	 */
	public const MAX_STORED_BYTES = 65535;

	private const KIND_INTEGER = 'integer';
	private const KIND_NULLABLE_INTEGER = 'nullableInteger';
	private const KIND_FLAG = 'flag';
	private const KIND_STRING = 'string';
	private const KIND_INTEGER_LIST = 'integerList';

	private const SUB_KEY_SEPARATOR = '.';

	/** Leaves of the column, keyed by the path of the sub key holding one. */
	private const LEAVES = [
		self::DEAL_CATEGORY => ['kind' => self::KIND_NULLABLE_INTEGER, 'default' => null],
		'DEAL_DC_ENABLED' => ['kind' => self::KIND_FLAG, 'default' => false],
		'DYNAMIC_CATEGORY' => ['kind' => self::KIND_NULLABLE_INTEGER, 'default' => null],
		'DYNAMIC_DC_ENABLED' => ['kind' => self::KIND_FLAG, 'default' => false],
		'REDIRECT_DELAY' => ['kind' => self::KIND_INTEGER, 'default' => Form::REDIRECT_DELAY],
		'REFILL.ACTIVE' => ['kind' => self::KIND_FLAG, 'default' => false],
		'REFILL.CAPTION' => ['kind' => self::KIND_STRING, 'default' => ''],
		'DISABLED_PAY_SYSTEMS' => ['kind' => self::KIND_INTEGER_LIST, 'default' => []],
	];

	/**
	 * Sub keys a created form carries as empty trees, the way the legacy write path creates them.
	 * Their own contracts write them node by node, so the paths of the form do not describe them.
	 */
	private const CREATION_TREES = [self::VIEWS, 'DESIGN'];

	/**
	 * The sub key a path of the form is stored in. This is the only place the correspondence of a
	 * public name and a key of the column is written down: the read answers the same sub key the
	 * write puts the value into, and neither direction carries a copy of the map.
	 *
	 * The sub keys of the smart process keep the word the legacy editor wrote them with, while the
	 * public names follow the one the contract of CRM V2 publishes. Renaming a stored key would
	 * leave the settings of every form the builder has already saved unread.
	 *
	 * A sub key standing inside another one is written down with a dot, the way a path of the form
	 * names a property of an object inside a section.
	 */
	private const SUB_KEY_BY_PATH = [
		WebFormPath::DEAL_CATEGORY_ID => self::DEAL_CATEGORY,
		WebFormPath::DEAL_DUPLICATE_CONTROL_ENABLED => 'DEAL_DC_ENABLED',
		WebFormPath::SMART_PROCESS_CATEGORY_ID => 'DYNAMIC_CATEGORY',
		WebFormPath::SMART_PROCESS_DUPLICATE_CONTROL_ENABLED => 'DYNAMIC_DC_ENABLED',
		WebFormPath::REDIRECT_DELAY => 'REDIRECT_DELAY',
		WebFormPath::REFILL_ENABLED => 'REFILL.ACTIVE',
		WebFormPath::REFILL_CAPTION => 'REFILL.CAPTION',
		WebFormPath::DISABLED_PAY_SYSTEMS_ID => 'DISABLED_PAY_SYSTEMS',
	];

	/**
	 * The column of a newly created form: every leaf seeded with the value the read answers for it.
	 * The builder reads several of these sub keys without allowing for their absence, so a form
	 * created without the seed cannot be opened for editing at all.
	 *
	 * @return array<string, mixed>
	 */
	public static function creationDefaults(): array
	{
		$defaults = [];
		foreach (self::CREATION_TREES as $subKey)
		{
			$defaults[$subKey] = [];
		}

		foreach (self::LEAVES as $subKeyPath => $leaf)
		{
			$defaults = self::setDeep($defaults, self::keysOf($subKeyPath), self::toStored($leaf, $leaf['default']));
		}

		return $defaults;
	}

	/**
	 * Sub keys the change set writes, in the form the column holds them, keyed by the path of the
	 * sub key. A change set touching nothing of this column answers with an empty set of writes.
	 *
	 * @return array<string, mixed>
	 */
	public static function writes(WebFormChangeSet $changeSet): array
	{
		$writes = [];
		foreach ($changeSet->paths() as $path)
		{
			$subKeyPath = self::SUB_KEY_BY_PATH[$path] ?? null;
			if ($subKeyPath === null)
			{
				continue;
			}

			$writes[$subKeyPath] = self::toStored(self::LEAVES[$subKeyPath], $changeSet->get($path));
		}

		return $writes;
	}

	/**
	 * The stored column with the sent sub keys applied to it. Missing intermediate nodes are
	 * created, and a column holding anything but a tree is merged into as if it were empty.
	 *
	 * @param array<string, mixed> $writes values in the form the column holds them
	 * @return array<string, mixed>
	 */
	public static function merge(mixed $stored, array $writes): array
	{
		$merged = is_array($stored) ? $stored : [];
		foreach ($writes as $subKeyPath => $value)
		{
			$merged = self::setDeep($merged, self::keysOf((string)$subKeyPath), $value);
		}

		return $merged;
	}

	/**
	 * Bytes the tree takes in the column, measured on the very value the storage writes.
	 *
	 * @param array<string, mixed> $settings
	 */
	public static function storedSize(array $settings): int
	{
		return strlen(serialize($settings));
	}

	/**
	 * The stored column a write of the embedding merges into: the block of the variants filled with
	 * the defaults while the column holds it empty, and left alone otherwise.
	 *
	 * A form nobody has placed anywhere carries the block empty, and every reader of the column
	 * answers the defaults of the whole tree for it - {@see ViewSettings::fromStoredViews()} and
	 * {@see Form::loadOnlyForm()} alike. Merging one node into an empty block would leave it
	 * non-empty and every setting the write said nothing about unanswered by both of them, so the
	 * defaults are laid under the written node instead.
	 *
	 * @return array<string, mixed>
	 */
	public static function withDefaultViews(mixed $stored): array
	{
		$settings = is_array($stored) ? $stored : [];
		$views = $settings[self::VIEWS] ?? null;
		if (!is_array($views) || $views === [])
		{
			$settings[self::VIEWS] = ViewSettings::DEFAULT_VIEWS;
		}

		return $settings;
	}

	/**
	 * The path of a sub key inside the block of the embedding variants, as {@see merge()} takes it.
	 * The variants are written node by node by a contract of their own, so the paths of the form do
	 * not describe them and the name of the block stays here, with the column holding it.
	 */
	public static function viewsPath(string $storedPath): string
	{
		return self::VIEWS . self::SUB_KEY_SEPARATOR . $storedPath;
	}

	/** Tells whether the path is stored in this column instead of a column of its own. */
	public static function owns(string $path): bool
	{
		return isset(self::SUB_KEY_BY_PATH[$path]);
	}

	/**
	 * Paths of the form stored in this column.
	 *
	 * @return string[]
	 */
	public static function paths(): array
	{
		return array_keys(self::SUB_KEY_BY_PATH);
	}

	/**
	 * The value of a path read off the stored column: the type of the contract, with the declared
	 * default standing in for anything the column does not hold in a usable form. The read never
	 * refuses. An unselected path answers null, the way every other read of this layer does.
	 *
	 * @return null|bool|int|string|int[]
	 */
	public static function value(mixed $stored, string $path, bool $selected): mixed
	{
		if (!$selected)
		{
			return null;
		}

		$subKeyPath = self::SUB_KEY_BY_PATH[$path] ?? null;
		if ($subKeyPath === null)
		{
			throw new InvalidArgumentException(
				sprintf('Web form path `%s` is stored outside the settings column', $path),
			);
		}

		$leaf = self::LEAVES[$subKeyPath];

		return self::fromStored($leaf, self::getDeep($stored, self::keysOf($subKeyPath)));
	}

	/**
	 * @return string[]
	 */
	private static function keysOf(string $subKeyPath): array
	{
		return explode(self::SUB_KEY_SEPARATOR, $subKeyPath);
	}

	/**
	 * @param array<string, mixed> $target
	 * @param string[] $keys
	 * @return array<string, mixed>
	 */
	private static function setDeep(array $target, array $keys, mixed $value): array
	{
		$key = (string)array_shift($keys);
		if ($keys === [])
		{
			$target[$key] = $value;

			return $target;
		}

		$node = $target[$key] ?? null;
		$target[$key] = self::setDeep(is_array($node) ? $node : [], $keys, $value);

		return $target;
	}

	/**
	 * @param string[] $keys
	 */
	private static function getDeep(mixed $stored, array $keys): mixed
	{
		$node = $stored;
		foreach ($keys as $key)
		{
			if (!is_array($node) || !array_key_exists($key, $node))
			{
				return null;
			}

			$node = $node[$key];
		}

		return $node;
	}

	/**
	 * @param array{kind: string, default: mixed} $leaf
	 */
	private static function toStored(array $leaf, mixed $value): mixed
	{
		// Clearing a leaf the contract does not allow to be empty stores the default of the leaf:
		// that is the value the read answers for an empty one anyway, and the builder reads several
		// of these sub keys without allowing for an empty value.
		return match ($leaf['kind'])
		{
			self::KIND_FLAG => $value === true || $value === 1 || $value === '1' || $value === 'Y' ? 'Y' : 'N',
			self::KIND_NULLABLE_INTEGER => $value === null ? null : (int)$value,
			self::KIND_INTEGER => (int)($value ?? $leaf['default']),
			self::KIND_STRING => (string)($value ?? $leaf['default']),
			self::KIND_INTEGER_LIST => self::toIntegerList($value),
		};
	}

	/**
	 * @param array{kind: string, default: mixed} $leaf
	 * @return null|bool|int|string|int[]
	 */
	private static function fromStored(array $leaf, mixed $value): mixed
	{
		return match ($leaf['kind'])
		{
			self::KIND_FLAG => $value === 'Y',
			self::KIND_NULLABLE_INTEGER, self::KIND_INTEGER =>
				is_numeric($value) ? (int)$value : $leaf['default'],
			self::KIND_STRING => is_scalar($value) ? (string)$value : $leaf['default'],
			self::KIND_INTEGER_LIST => self::toIntegerList($value),
		};
	}

	/**
	 * @return int[]
	 */
	private static function toIntegerList(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		return array_values(array_map(
			static fn (mixed $item): int => (int)$item,
			array_filter($value, static fn (mixed $item): bool => is_numeric($item)),
		));
	}
}
