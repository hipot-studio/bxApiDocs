<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

/**
 * What makes a folder of the target server of a migration the pair of a known folder of the old one.
 *
 * A root folder is paired by its system role or by the last part of its name; a nested folder by
 * its full name under the pair of its parent. Exact names precede canonical names and root-only
 * case-insensitive names, with the INBOX prefix compared case insensitively. The same rules answer
 * the inspection of the target server over the wire
 * {@see \Bitrix\Mail\Helper\Mailbox\MailboxConnector::inspectKnownMigrationConnectionSnapshot()}
 * and the comparison with the stored folders of the prepared generation {@see LateFolderReconciler}.
 */
final class FolderPairRules
{
	public const ROLE_INBOX = 'inbox';

	/**
	 * @param string[] $flags IMAP flags of a folder: the SPECIAL-USE ones name its role.
	 */
	public static function systemRole(array $flags): ?string
	{
		foreach ($flags as $flag)
		{
			$normalized = mb_strtolower(ltrim((string)$flag, '\\'));
			if ($normalized === 'sent' || $normalized === 'drafts' || $normalized === 'trash')
			{
				return $normalized;
			}
			if ($normalized === 'junk' || $normalized === 'spam')
			{
				return 'spam';
			}
		}

		return null;
	}

	/**
	 * The role a known folder plays for the mailbox: its system role or the inbox. A folder with a role
	 * is never created on the target server.
	 */
	public static function knownFolderRole(array $known, ?string $systemRole): ?string
	{
		if ($systemRole !== null)
		{
			return $systemRole;
		}

		$isInbox = (int)($known['IS_INCOME'] ?? 0) === 1
			|| ((int)($known['PARENT_ID'] ?? 0) <= 0 && mb_strtoupper((string)($known['NAME'] ?? '')) === 'INBOX');

		return $isInbox ? self::ROLE_INBOX : null;
	}

	/**
	 * Whether a root folder of the target server pairs a known root folder.
	 *
	 * @param array{name?: string, delim?: string, flags?: string[]} $candidate
	 */
	public static function rootMatches(?string $knownRole, string $knownName, array $candidate): bool
	{
		if ($knownRole !== null)
		{
			return self::systemRole((array)($candidate['flags'] ?? [])) === $knownRole;
		}

		return mb_strtoupper(self::canonicalSegment(self::lastPathPart(
			(string)($candidate['name'] ?? ''),
			(string)($candidate['delim'] ?? ''),
		))) === mb_strtoupper(self::canonicalSegment($knownName));
	}

	public static function canonicalSegment(string $segment): string
	{
		return preg_replace('~(*UCP)^\s+|\s+$~u', '', $segment) ?? $segment;
	}

	public static function canonicalPath(string $path, string $delimiter): string
	{
		if ($delimiter === '' || mb_strtoupper($delimiter) === 'NIL')
		{
			return self::canonicalSegment($path);
		}

		return implode($delimiter, array_map(self::canonicalSegment(...), explode($delimiter, $path)));
	}

	/**
	 * @param array<int, array{name?: string, delim?: string}> $candidates
	 * @return array<int, array{name?: string, delim?: string}>
	 */
	public static function matchingDirectories(string $expected, array $candidates, bool $isRoot): array
	{
		$exact = [];
		$canonical = [];
		$caseInsensitive = [];
		foreach ($candidates as $candidate)
		{
			$delimiter = (string)($candidate['delim'] ?? '');
			$actual = (string)($candidate['name'] ?? '');
			if ($isRoot)
			{
				$actual = self::lastPathPart($actual, $delimiter);
			}

			if (self::namesExactlyEqual($expected, $actual, $delimiter))
			{
				$exact[] = $candidate;
				continue;
			}

			$canonicalExpected = self::canonicalPath($expected, $delimiter);
			$canonicalActual = self::canonicalPath($actual, $delimiter);
			if (self::namesExactlyEqual($canonicalExpected, $canonicalActual, $delimiter))
			{
				$canonical[] = $candidate;
				continue;
			}

			if ($isRoot && mb_strtoupper($canonicalActual) === mb_strtoupper($canonicalExpected))
			{
				$caseInsensitive[] = $candidate;
			}
		}

		if ($exact !== [])
		{
			return $exact;
		}
		if ($canonical !== [])
		{
			return $canonical;
		}

		return $caseInsensitive;
	}

	public static function lastPathPart(string $path, string $delimiter): string
	{
		if ($delimiter === '' || mb_strtoupper($delimiter) === 'NIL')
		{
			return $path;
		}

		$position = mb_strrpos($path, $delimiter);

		return $position === false ? $path : mb_substr($path, $position + mb_strlen($delimiter));
	}

	public static function namesAreEqual(string $expected, string $actual, string $delimiter): bool
	{
		return self::namesExactlyEqual($expected, $actual, $delimiter)
			|| self::namesExactlyEqual(
				self::canonicalPath($expected, $delimiter),
				self::canonicalPath($actual, $delimiter),
				$delimiter,
			);
	}

	private static function namesExactlyEqual(string $expected, string $actual, string $delimiter): bool
	{
		if ($expected === $actual)
		{
			return true;
		}

		return self::startsWithInbox($expected, $delimiter)
			&& self::startsWithInbox($actual, $delimiter)
			&& substr($expected, strlen('INBOX')) === substr($actual, strlen('INBOX'));
	}

	private static function startsWithInbox(string $path, string $delimiter): bool
	{
		$inboxLength = strlen('INBOX');
		if (strlen($path) < $inboxLength || strcasecmp(substr($path, 0, $inboxLength), 'INBOX') !== 0)
		{
			return false;
		}

		return strlen($path) === $inboxLength
			|| (
				$delimiter !== ''
				&& mb_strtoupper($delimiter) !== 'NIL'
				&& substr($path, $inboxLength, strlen($delimiter)) === $delimiter
			);
	}
}
