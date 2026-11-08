<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Source;

use Bitrix\Im\V2\Folder\System\AllFolder;

/**
 * Difference between the composition stored for a composite folder and the snapshot the client
 * sent back (ALG-02): the codes to write, the codes to drop, the composition both leave behind and
 * the switchable sources left outside it.
 *
 * The merge reads no table and resolves no folder - its only dependency outside the arguments is
 * the canonical source registry ({@see AllFolder::getDefaultDefinition}), so the whole of the
 * normalization the client never sees (an unknown code dropped, the mandatory source appended, the
 * stored choice on sources the client could not show left alone) is checkable without a database.
 */
final readonly class SourceComposition
{
	/**
	 * @param string[] $toInsert codes with no row yet
	 * @param string[] $toRemove stored codes whose row must go
	 * @param string[] $enabled the whole composition after the save, the two lists above already
	 *        applied - the input of the side effects of the write
	 *        ({@see FolderSourceService::updateSources()}). Never empty: the mandatory source is
	 *        appended to it whatever the client sent.
	 * @param string[] $disabled the counterpart of $enabled among the sources the user may switch:
	 *        what he left off, in the order of $manageable. Counted against $manageable and not
	 *        against the registry, so the mandatory source, a source unavailable to him and a
	 *        showcase one are all outside of it by construction.
	 */
	private function __construct(
		public array $toInsert,
		public array $toRemove,
		public array $enabled,
		public array $disabled,
	) {}

	/**
	 * Every list speaks the stored codes of the sources: the snapshot of the client arrives in wire
	 * codes and is translated by {@see FolderSourceService::updateSources()} before it gets here.
	 *
	 * @param string[] $stored codes really stored in b_im_folder_source for the folder
	 * @param string[] $incomingStoredCodes full snapshot of the codes the client wants enabled
	 * @param string[] $manageable canonical codes of the sources available to the user
	 * @param string[] $mandatory canonical codes the server always keeps enabled
	 */
	public static function merge(array $stored, array $incomingStoredCodes, array $manageable, array $mandatory): self
	{
		$stored = self::normalizeCodes($stored);
		$manageableCodes = array_fill_keys(self::normalizeCodes($manageable), true);

		// AC-020: a code outside the canonical registry is never stored, whatever the client sent -
		// a showcase source is shown locked and must not become a real one through a save.
		$canonicalCodes = array_fill_keys(
			array_map(static fn ($entry) => $entry->code, AllFolder::getDefaultDefinition()),
			true,
		);

		$desired = [];
		foreach (self::normalizeCodes($incomingStoredCodes) as $code)
		{
			if (isset($canonicalCodes[$code], $manageableCodes[$code]))
			{
				$desired[$code] = true;
			}
		}

		// D17: a stored code the client could not show is a decision the user never made, so it
		// survives the save. Covers both a source unavailable to him and a code the registry lost.
		foreach ($stored as $code)
		{
			if (!isset($manageableCodes[$code]))
			{
				$desired[$code] = true;
			}
		}

		// D5: the mandatory source comes back silently, so a stored composition is never empty.
		foreach (self::normalizeCodes($mandatory) as $code)
		{
			$desired[$code] = true;
		}

		$storedCodes = array_fill_keys($stored, true);

		$toInsert = [];
		foreach (array_keys($desired) as $code)
		{
			if (!isset($storedCodes[$code]))
			{
				$toInsert[] = (string)$code;
			}
		}

		$toRemove = [];
		foreach ($stored as $code)
		{
			if (!isset($desired[$code]))
			{
				$toRemove[] = $code;
			}
		}

		$disabled = [];
		foreach (array_keys($manageableCodes) as $code)
		{
			if (!isset($desired[$code]))
			{
				$disabled[] = (string)$code;
			}
		}

		return new self($toInsert, $toRemove, array_map('strval', array_keys($desired)), $disabled);
	}

	public function isEmpty(): bool
	{
		return $this->toInsert === [] && $this->toRemove === [];
	}

	/**
	 * Unique non-empty codes as strings. Numeric-looking codes come back as strings as well:
	 * the lookups below are built as array keys, and PHP would hand such a key back as an int.
	 *
	 * @return string[]
	 */
	private static function normalizeCodes(array $codes): array
	{
		$unique = [];
		foreach ($codes as $code)
		{
			if (!is_scalar($code))
			{
				continue;
			}

			$code = (string)$code;
			if ($code !== '')
			{
				$unique[$code] = true;
			}
		}

		return array_map('strval', array_keys($unique));
	}
}
