<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\File;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Disk\Version;

/**
 * Resolves the durable disk artifact created by a docked commit from its
 * intent token.
 *
 * The intent token is written into UPLOAD_INTENT_TOKEN on the disk object
 * (new-file branch) or on the version row (version/replace branch), each guarded
 * by a DB-level UNIQUE index. This class is the single place that translates a
 * token back into the artifact and, on a unique-violation, discriminates the two
 * possible causes:
 *
 *  - token found  -> the object/version with OUR token already exists, i.e. a
 *    concurrent or crashed attempt already performed the domain write; the
 *    caller reconciles (no second write).
 *  - token absent -> the violation was a (NAME, PARENT_ID) collision, i.e. a real
 *    duplicate-name conflict; the caller reports duplicate_conflict.
 *
 * The discrimination relies purely on database STATE (re-reading by token), never
 * on parsing the driver message or the offending index name, so it is
 * cross-DBMS (MySQL / PostgreSQL) correct.
 */
class CommitReconciler
{
	/**
	 * How many intent tokens go into one IN(...) probe. Keeps the reconciliation
	 * lookup on a big restored tree to a handful of bounded queries instead of one
	 * per item (see QueueRestoreProvider batch reconciliation).
	 */
	private const TOKEN_LOOKUP_CHUNK = 500;

	public function findFileByIntentToken(string $intentToken): ?File
	{
		if ($intentToken === '')
		{
			return null;
		}

		return File::load(['=UPLOAD_INTENT_TOKEN' => $intentToken]);
	}

	public function findVersionByIntentToken(string $intentToken): ?Version
	{
		if ($intentToken === '')
		{
			return null;
		}

		return Version::load(['=UPLOAD_INTENT_TOKEN' => $intentToken]);
	}

	/**
	 * Batch existence probe for the new-file branch: which of the given intent
	 * tokens already carry a live disk object (crash-after-domain-write window).
	 *
	 * The filter mirrors {@see File::load} (live file, not trashed) so a token
	 * reported here is guaranteed to be resolvable by {@see findFileByIntentToken}
	 * - the caller can then drive an idempotent guarded finalize without a risk of
	 * re-materialisation.
	 *
	 * @param string[] $intentTokens
	 * @return array<string, true> the subset of tokens with a durable object
	 */
	public function findExistingFileIntentTokens(array $intentTokens): array
	{
		$found = [];
		foreach ($this->chunkTokens($intentTokens) as $chunk)
		{
			$rows = ObjectTable::getList([
				'select' => ['UPLOAD_INTENT_TOKEN'],
				'filter' => [
					'@UPLOAD_INTENT_TOKEN' => $chunk,
					'=TYPE' => ObjectTable::TYPE_FILE,
					'=DELETED_TYPE' => ObjectTable::DELETED_TYPE_NONE,
				],
			]);
			foreach ($rows as $row)
			{
				$found[(string)$row['UPLOAD_INTENT_TOKEN']] = true;
			}
		}

		return $found;
	}

	/**
	 * Batch existence probe for the version/replace branch: which of the given
	 * intent tokens already carry a version row.
	 *
	 * @param string[] $intentTokens
	 * @return array<string, true> the subset of tokens with a durable version
	 */
	public function findExistingVersionIntentTokens(array $intentTokens): array
	{
		$found = [];
		foreach ($this->chunkTokens($intentTokens) as $chunk)
		{
			$rows = VersionTable::getList([
				'select' => ['UPLOAD_INTENT_TOKEN'],
				'filter' => ['@UPLOAD_INTENT_TOKEN' => $chunk],
			]);
			foreach ($rows as $row)
			{
				$found[(string)$row['UPLOAD_INTENT_TOKEN']] = true;
			}
		}

		return $found;
	}

	/**
	 * @param string[] $intentTokens
	 * @return array<int, string[]> non-empty unique tokens, chunked
	 */
	private function chunkTokens(array $intentTokens): array
	{
		$tokens = array_values(array_unique(array_filter(
			$intentTokens,
			static fn($token): bool => is_string($token) && $token !== '',
		)));

		if ($tokens === [])
		{
			return [];
		}

		return array_chunk($tokens, self::TOKEN_LOOKUP_CHUNK);
	}
}
