<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\Models\DocumentSessionTable;
use Bitrix\Disk\Document\Vibeoffice\Models\PlatformSessionTable;
use Bitrix\Main\Web\Json;

/**
 * The durable carrier of an open attempt is the physical row of its local session (CONTEXT with the
 * attempt states) together with the registry row of its token. While an attempt of the carrier still has
 * an unknown outcome or a debt, the row stays as an inactive tombstone instead of being deleted.
 */
final class OpenDebtCarrier
{
	private const CHUNK_SIZE = 500;

	/**
	 * IDs of the given local sessions whose rows must not be deleted.
	 *
	 * @param int[] $sessionIds
	 * @return int[]
	 */
	public static function filterProtected(array $sessionIds): array
	{
		$sessionIds = array_values(array_unique(array_filter(
			array_map('intval', $sessionIds),
			static fn(int $id): bool => $id > 0,
		)));
		$rows = [];
		foreach (array_chunk($sessionIds, self::CHUNK_SIZE) as $chunk)
		{
			array_push($rows, ...DocumentSessionTable::query()
				->setSelect(['ID', 'CONTEXT'])
				->whereIn('ID', $chunk)
				->where('SERVICE', DocumentService::Vibeoffice->value)
				->fetchAll());
		}

		return self::filterProtectedRows($rows);
	}

	/**
	 * IDs of the given Vibeoffice local session rows (ID and CONTEXT, read by the caller, under a lock when
	 * the answer guards a deletion) whose rows must not be deleted.
	 *
	 * @param array[] $rows
	 * @return int[]
	 */
	public static function filterProtectedRows(array $rows): array
	{
		$protected = [];
		$ids = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$ids[] = $id;
			if (self::contextProtects($row['CONTEXT'] ?? null))
			{
				$protected[$id] = $id;
			}
		}
		foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk)
		{
			$pending = PlatformSessionTable::query()
				->setSelect(['DOCUMENT_SESSION_ID'])
				->whereIn('DOCUMENT_SESSION_ID', $chunk)
				->where('STATUS', PlatformSessionTable::STATUS_PENDING)
				->whereNotNull('OPEN_TOKEN')
				->setDistinct()
				->fetchAll()
			;
			foreach ($pending as $row)
			{
				$id = (int)$row['DOCUMENT_SESSION_ID'];
				$protected[$id] = $id;
			}
		}

		return array_values($protected);
	}

	public static function isProtected(int $sessionId): bool
	{
		return self::filterProtected([$sessionId]) !== [];
	}

	/**
	 * Whether a registry row with this token must be kept: the attempt state of the token is corrupt or still
	 * in debt, or the carrier of a pending row is gone (an orphan is unknown). A carrier is deleted physically
	 * only without debt, so a settled row (STATUS other than pending) whose carrier is gone protects nothing.
	 */
	public static function protectsToken(string $openToken, int $sessionId): bool
	{
		return self::filterProtectedTokens([['OPEN_TOKEN' => $openToken, 'DOCUMENT_SESSION_ID' => $sessionId]]) !== [];
	}

	/**
	 * The same for a batch of registry rows (OPEN_TOKEN, DOCUMENT_SESSION_ID, optional STATUS; a row without
	 * it counts as pending): the carriers are read with one query per chunk and each of them is decoded once.
	 *
	 * @param array[] $rows
	 * @return array<string, true> protected tokens
	 */
	public static function filterProtectedTokens(array $rows): array
	{
		$sessionIds = [];
		foreach ($rows as $row)
		{
			$sessionId = (int)$row['DOCUMENT_SESSION_ID'];
			if ($sessionId > 0)
			{
				$sessionIds[$sessionId] = $sessionId;
			}
		}
		$carriers = [];
		foreach (array_chunk(array_values($sessionIds), self::CHUNK_SIZE) as $chunk)
		{
			$contexts = DocumentSessionTable::query()->setSelect(['ID', 'CONTEXT'])->whereIn('ID', $chunk)->fetchAll();
			foreach ($contexts as $context)
			{
				$carriers[(int)$context['ID']] = self::decodeVo($context['CONTEXT'] ?? null);
			}
		}

		$protected = [];
		foreach ($rows as $row)
		{
			$token = (string)$row['OPEN_TOKEN'];
			$sessionId = (int)$row['DOCUMENT_SESSION_ID'];
			if (!array_key_exists($sessionId, $carriers))
			{
				if (($row['STATUS'] ?? PlatformSessionTable::STATUS_PENDING) === PlatformSessionTable::STATUS_PENDING)
				{
					$protected[$token] = true;
				}

				continue;
			}
			$vo = $carriers[$sessionId];
			if ($vo === null || self::voProtectsToken($vo, $token))
			{
				$protected[$token] = true;
			}
		}

		return $protected;
	}

	private static function voProtectsToken(array $vo, string $openToken): bool
	{
		$map = OpenAttemptState::readMap($vo);
		if ($map === null)
		{
			return true;
		}
		if (!array_key_exists($openToken, $map))
		{
			return OpenAttemptState::isLatestToken($vo, $openToken);
		}

		return !OpenAttemptState::isValid($map[$openToken]) || OpenAttemptState::hasDebt($map[$openToken]);
	}

	public static function contextProtects(mixed $rawContext): bool
	{
		$vo = self::decodeVo($rawContext);

		return $vo === null || OpenAttemptState::protectsCarrier($vo);
	}

	/** The vo metadata of a raw CONTEXT; null when it cannot be read and so proves nothing. */
	private static function decodeVo(mixed $rawContext): ?array
	{
		if ($rawContext === null || $rawContext === '')
		{
			return [];
		}
		if (!is_string($rawContext))
		{
			return null;
		}
		try
		{
			$context = Json::decode($rawContext);
		}
		catch (\Throwable)
		{
			return null;
		}
		if (!is_array($context))
		{
			return null;
		}
		$vo = $context[DocumentSessionManager::CONTEXT_VO_KEY] ?? [];

		return is_array($vo) ? $vo : null;
	}
}
