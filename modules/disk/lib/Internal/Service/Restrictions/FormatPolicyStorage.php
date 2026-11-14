<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes the saved snapshot of the global file format policy.
 *
 * Only the saved resulting list of extensions is applied; presets are the state of the settings
 * interface and are never expanded into extensions here. The snapshot lives in a portal option
 * and is read several times over one write, so the parsed value is kept until the policy is
 * saved: the storage is one object for the whole request, and the save goes through it.
 *
 * A policy that is not there and a policy that cannot be read are two different things. The first
 * is the ordinary state of a portal nobody restricted anything on; the second is a restriction
 * that was set up and is not applied any more, and it is the only one written down: an empty
 * policy is answered either way, because a write path that throws is worse than one that lets
 * a file through, but the reason must not disappear along with the restriction.
 */
class FormatPolicyStorage
{
	public const OPTION_NAME = 'disk_restrictions_format_policy';
	public const VERSION = 2;

	protected const LOG_FEATURE = 'disk restrictions format policy';

	private ?array $parsedSnapshot = null;
	private ?array $parsedExtensionMap = null;
	private ?LoggerInterface $logger = null;

	/**
	 * @param LoggerInterface|null $logger Journal of the module, the standard one by default.
	 */
	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger;
	}

	/**
	 * Returns the saved policy snapshot. A missing or unreadable option means an empty policy.
	 *
	 * @return array
	 */
	public function read(): array
	{
		return $this->parsedSnapshot ??= $this->parseSnapshot();
	}

	/**
	 * Returns the restricted extensions as a map to look up by: the policy holds up to
	 * ExtensionListNormalizer::MAX_COUNT of them, and every write asks about one name at least.
	 *
	 * @return array
	 */
	public function readExtensionMap(): array
	{
		return $this->parsedExtensionMap ??= array_flip($this->read()['extensions']);
	}

	protected function parseSnapshot(): array
	{
		$rawValue = $this->readOption();
		if (trim($rawValue) === '')
		{
			return $this->createEmptyPolicy();
		}

		try
		{
			$snapshot = Json::decode($rawValue);
		}
		catch (Throwable $exception)
		{
			$this->logUnreadableSnapshot('the saved format policy cannot be parsed', $rawValue, [
				'exception' => $exception->getMessage(),
			]);

			return $this->createEmptyPolicy();
		}

		if (!is_array($snapshot))
		{
			$this->logUnreadableSnapshot('the saved format policy is not a snapshot', $rawValue, [
				'type' => get_debug_type($snapshot),
			]);

			return $this->createEmptyPolicy();
		}

		// A snapshot of version 1 knows nothing of the block of files without an extension and is
		// read as version 2 with the block off: the option is never rewritten for that.
		return [
			'version' => self::VERSION,
			'extensions' => $this->toStringList($snapshot['extensions'] ?? []),
			'presets' => $this->toStringList($snapshot['presets'] ?? []),
			'blockNoExtension' => ($snapshot['blockNoExtension'] ?? false) === true,
			'savedAt' => (int)($snapshot['savedAt'] ?? 0),
		];
	}

	/**
	 * Tells whether the saved policy restricts nothing: no extensions and no block of files without
	 * an extension. Presets alone restrict nothing, they are the state of the interface.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool
	{
		$policy = $this->read();

		return empty($policy['extensions']) && !$policy['blockNoExtension'];
	}

	/**
	 * Saves the policy snapshot and returns it as it was stored.
	 *
	 * @param array $extensions Normalized resulting list of extensions, the only applied part.
	 * @param array $presets Ids of the presets chosen in the interface.
	 * @param bool $blockNoExtension Whether files without an extension are restricted.
	 * @return array
	 * @throws ArgumentException
	 */
	public function save(array $extensions, array $presets, bool $blockNoExtension = false): array
	{
		$snapshot = [
			'version' => self::VERSION,
			'extensions' => $this->toStringList($extensions),
			'presets' => $this->toStringList($presets),
			'blockNoExtension' => $blockNoExtension,
			'savedAt' => $this->getCurrentTimestamp(),
		];

		$this->writeOption(Json::encode($snapshot));

		// What was saved is what the next read must answer with, this very request included.
		$this->parsedSnapshot = null;
		$this->parsedExtensionMap = null;

		return $snapshot;
	}

	protected function createEmptyPolicy(): array
	{
		return [
			'version' => self::VERSION,
			'extensions' => [],
			'presets' => [],
			'blockNoExtension' => false,
			'savedAt' => 0,
		];
	}

	protected function toStringList(mixed $values): array
	{
		if (!is_array($values))
		{
			return [];
		}

		$list = [];
		foreach ($values as $value)
		{
			if (is_scalar($value))
			{
				$list[] = (string)$value;
			}
		}

		return $list;
	}

	protected function getCurrentTimestamp(): int
	{
		return time();
	}

	/**
	 * The stored value itself never reaches the journal: it is as long as the policy is, and its
	 * length together with the reason is what tells a broken write from a hand-made edit.
	 */
	protected function logUnreadableSnapshot(string $reason, string $rawValue, array $context = []): void
	{
		$this->getLogger()->warning($reason, array_merge(
			[
				'option' => self::OPTION_NAME,
				'length' => strlen($rawValue),
			],
			$context,
		));
	}

	protected function getLogger(): LoggerInterface
	{
		return $this->logger ??= LoggerFactory::create(feature: static::LOG_FEATURE);
	}

	protected function readOption(): string
	{
		return (string)Option::get(Driver::INTERNAL_MODULE_ID, self::OPTION_NAME, '');
	}

	protected function writeOption(string $value): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, self::OPTION_NAME, $value);
	}
}
