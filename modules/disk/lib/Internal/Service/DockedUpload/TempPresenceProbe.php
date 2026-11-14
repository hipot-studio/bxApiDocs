<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\UI\FileUploader\TempFileTable;

/**
 * Batched temp-presence check for a set of transport tokens (R6).
 *
 * Resolves whether the bytes behind each token are still in temp with a handful of
 * chunked {@see TempFileTable} IN(...) queries - never a per-token
 * {@see \Bitrix\UI\FileUploader\Uploader::getPendingFiles()} / getStatus, which costs a
 * DB query each. The token GUID is extracted in PHP (the first token segment, exactly as
 * the transport signs it) so the lookup keys straight on the GUID column; the HMAC
 * signature is intentionally NOT re-verified here - the tokens come from the user's own
 * trusted queue rows, this is a read-only presence check that materialises nothing, and
 * the CREATED_BY filter enforces ownership.
 *
 * Both callers that need the verdict for a whole queue at once share it: the restore
 * reconciliation ({@see \Bitrix\Disk\Public\Provider\DockedUpload\QueueRestoreProvider})
 * and the re-commit of an explicitly resumed queue
 * ({@see \Bitrix\Disk\Public\Command\DockedUpload\ResumeCommand}, API-07), so the two
 * cannot drift into asking the transport in different ways. Every path that FREES temp
 * shares the same lookup through {@see self::filterOwnPresent()}, for the same reason.
 */
final class TempPresenceProbe
{
	/**
	 * Chunk size for the batched IN(...) probes, and the base for the upper bound on how
	 * many tokens a single call reconciles. It caps the per-request work regardless of how
	 * many queues (or how large a queue) the user accumulated.
	 */
	public const LOOKUP_CHUNK = 500;

	/**
	 * The per-request ceiling (LOOKUP_CHUNK * 4 unique GUIDs) can leave a tail of tokens
	 * unverified; those are reported back as a set (second return value) so the caller can
	 * tell a token degraded purely by the ceiling - its verdict is provisional - from one
	 * whose temp genuinely disappeared. A structurally malformed token (extractGuid ->
	 * null) is NOT truncation: it is honestly absent and never enters that set.
	 *
	 * @param string[] $tokens
	 * @return array{0: array<string, bool>, 1: array<string, true>} the token => present
	 *   validity map and the set of tokens left unverified by the ceiling
	 */
	public function resolve(array $tokens, int $userId): array
	{
		$map = [];
		$uncheckedTokens = [];
		if ($tokens === [])
		{
			return [$map, $uncheckedTokens];
		}

		// Extract token -> guid, capping the reconciled volume per request. Every token
		// past the ceiling is left unverified this call and reported as such.
		$uniqueTokens = array_values(array_unique($tokens));
		$guidToTokens = [];
		foreach ($uniqueTokens as $index => $token)
		{
			if (count($guidToTokens) >= self::LOOKUP_CHUNK * 4)
			{
				$uncheckedTokens = array_fill_keys(array_slice($uniqueTokens, $index), true);

				break;
			}

			$guid = $this->extractGuid($token);
			if ($guid === null)
			{
				continue;
			}

			$guidToTokens[$guid][] = $token;
		}

		if ($guidToTokens === [])
		{
			return [$map, $uncheckedTokens];
		}

		$presentGuids = [];
		foreach (array_chunk(array_keys($guidToTokens), self::LOOKUP_CHUNK) as $guidChunk)
		{
			$rows = TempFileTable::getList([
				'select' => ['GUID'],
				'filter' => [
					'=GUID' => $guidChunk,
					'=UPLOADED' => true,
					'=CREATED_BY' => $userId,
				],
			]);
			foreach ($rows as $row)
			{
				$presentGuids[(string)$row['GUID']] = true;
			}
		}

		foreach ($guidToTokens as $guid => $tokensForGuid)
		{
			$present = isset($presentGuids[$guid]);
			foreach ($tokensForGuid as $token)
			{
				$map[$token] = $present;
			}
		}

		return [$map, $uncheckedTokens];
	}

	/**
	 * The tokens whose bytes are still in temp AND belong to $userId - the only ones a
	 * caller may hand to {@see \Bitrix\UI\FileUploader\Uploader::remove()}.
	 *
	 * remove() deletes a temp row by the GUID inside the token and never compares
	 * CREATED_BY, while the token on a queue row is whatever the client wrote there, so
	 * the ownership check belongs to the release path - and to ONE place, shared by all
	 * four of them (widget close, cancel, conflict cancel, and the TTL cleanup agent,
	 * which asks it per queue owner) instead of re-stated in each.
	 * A token left unverified by the per-request ceiling of {@see self::resolve()} is not
	 * returned either. The tail keeps a backstop on both sides of the boundary: a command
	 * leaves the row behind, so the 24h TTL agent re-asks about it later; the agent leaves
	 * no row, and there ui's own temp expiry
	 * ({@see \Bitrix\UI\FileUploader\TempFileAgent::clearOldRecords()}) reaps the file.
	 *
	 * @param string[] $tokens
	 * @return string[]
	 */
	public function filterOwnPresent(array $tokens, int $userId): array
	{
		[$presentByToken] = $this->resolve($tokens, $userId);

		$ownTokens = [];
		foreach ($presentByToken as $token => $isPresent)
		{
			if ($isPresent)
			{
				$ownTokens[] = (string)$token;
			}
		}

		return $ownTokens;
	}

	/**
	 * The transport signs a token as `guid.signature`; the GUID is the first segment.
	 * Returns null for a structurally malformed token, which the callers treat as bytes
	 * they cannot confirm.
	 */
	private function extractGuid(string $token): ?string
	{
		$parts = explode('.', $token, 2);
		if (count($parts) !== 2)
		{
			return null;
		}

		[$guid, $signature] = $parts;
		if ($guid === '' || $signature === '')
		{
			return null;
		}

		return $guid;
	}
}
