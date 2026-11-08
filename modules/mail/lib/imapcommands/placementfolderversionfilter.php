<?php

namespace Bitrix\Mail\ImapCommands;

use Bitrix\Main\Type\DateTime;

/**
 * Of the rows a command names, the ones written under the version their folder carries now.
 *
 * A folder the server has recreated answers with a version of its own and numbers its letters anew, so
 * a row written under a previous version addresses nothing: the same number names another letter of that
 * folder, and a command carried to it would be carried out on that letter. Such a row stays where it is -
 * the reconciliation of the synchronization is what decides its fate - but a command of a user is not
 * carried to it.
 */
final class PlacementFolderVersionFilter
{
	/**
	 * Which version a folder carries now is read off the rows themselves, without asking the server: of the
	 * versions the rows of one folder name, the one written last is in force.
	 *
	 * Told by the moment of writing and not by the value of the version: the version is a number the server
	 * picks, and a server recreating a folder against RFC 3501 2.3.1.1 - the very kind of server this guard
	 * exists for - may pick a smaller one than before. The moment of writing says which version is in force
	 * without trusting the server to count upwards; the value decides only between versions written in the
	 * same second, and then the larger one wins, the way the protocol says it should.
	 *
	 * A folder that has rows of one version only is left as it is - whether the server has moved on cannot
	 * be told from here. A row naming no version at all is left too: the outgoing route writes it while it
	 * awaits its number.
	 *
	 * @param array $placements Rows of b_mail_message_uid with DIR_MD5, DIR_UIDV and DATE_INSERT.
	 */
	public static function dropPreviousVersions(array $placements): array
	{
		$versionInForce = [];

		foreach ($placements as $placement)
		{
			$version = (int)($placement['DIR_UIDV'] ?? 0);

			if ($version <= 0)
			{
				continue;
			}

			$folder = (string)($placement['DIR_MD5'] ?? '');
			$writtenAt = self::writtenAt($placement);
			$known = $versionInForce[$folder] ?? null;

			if ($known === null || self::isWrittenLater($writtenAt, $version, $known))
			{
				$versionInForce[$folder] = ['at' => $writtenAt, 'version' => $version];
			}
		}

		return array_values(array_filter(
			$placements,
			static function ($placement) use ($versionInForce)
			{
				$version = (int)($placement['DIR_UIDV'] ?? 0);
				$folder = (string)($placement['DIR_MD5'] ?? '');

				return $version === 0 || $version === ($versionInForce[$folder]['version'] ?? $version);
			}
		));
	}

	/** @param array{at: int, version: int} $known */
	private static function isWrittenLater(int $writtenAt, int $version, array $known): bool
	{
		if ($writtenAt !== $known['at'])
		{
			return $writtenAt > $known['at'];
		}

		return $version > $known['version'];
	}

	/**
	 * A row that does not say when it was written stands before every row that does: it cannot outweigh a
	 * dated one, and among such rows the version decides.
	 */
	private static function writtenAt(array $placement): int
	{
		$writtenAt = $placement['DATE_INSERT'] ?? null;

		if ($writtenAt instanceof DateTime)
		{
			return $writtenAt->getTimestamp();
		}

		if (is_string($writtenAt) && $writtenAt !== '')
		{
			return (int)strtotime($writtenAt);
		}

		return 0;
	}
}
