<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk\Dto;

/**
 * Payload of the signed token that addresses an uploaded large attachment set.
 *
 * The wire format is frozen: tokens of the schema are already issued to live drafts, the signature covers
 * the encoded JSON and the encoder keeps the order of the keys, so toArray() emits them in the order of
 * self::KEYS. A pending replacement appends the keys of self::PENDING_KEYS after the base ones.
 */
final class LargeAttachmentTokenPayload
{
	public const VERSION = 2;
	public const FOLDER_NAME_PREFIX = 'mail-attachments-';
	public const STAGING_FOLDER_NAME_PREFIX = 'mail-attachments-staging-';

	private const KEYS = [
		'version',
		'userId',
		'storageId',
		'mailFolderId',
		'folderId',
		'folderName',
		'linkObjectId',
		'externalLinkId',
		'externalLinkHash',
		'sourceFileIds',
		'copiedObjectIds',
	];

	private const PENDING_KEYS = [
		'stagingFolderId',
		'stagingFolderName',
		'stagedObjectIds',
		'previousExternalLinkId',
	];

	private const POSITIVE_INT_KEYS = [
		'userId',
		'storageId',
		'mailFolderId',
		'folderId',
		'linkObjectId',
		'externalLinkId',
	];

	/**
	 * The four fields of a pending replacement travel together, and isPendingReplacement() is the only way
	 * to ask whether they are set.
	 *
	 * @param int[] $sourceFileIds
	 * @param int[] $copiedObjectIds
	 * @param int[] $stagedObjectIds
	 */
	public function __construct(
		public readonly int $userId,
		public readonly int $storageId,
		public readonly int $mailFolderId,
		public readonly int $folderId,
		public readonly string $folderName,
		public readonly int $linkObjectId,
		public readonly int $externalLinkId,
		public readonly string $externalLinkHash,
		public readonly array $sourceFileIds,
		public readonly array $copiedObjectIds,
		public readonly int $stagingFolderId = 0,
		public readonly string $stagingFolderName = '',
		public readonly array $stagedObjectIds = [],
		public readonly int $previousExternalLinkId = 0,
	)
	{
	}

	/**
	 * Reads a decoded token payload, refusing anything that does not match the schema: the key set has to
	 * be exactly the base one or exactly the pending one, ids have to be integers, and the id lists have to
	 * come normalized and agree with each other.
	 */
	public static function fromArray(mixed $payload): ?self
	{
		if (!is_array($payload))
		{
			return null;
		}

		$actualKeys = array_keys($payload);
		sort($actualKeys);
		$baseKeys = self::KEYS;
		sort($baseKeys);
		$pendingKeys = [...self::KEYS, ...self::PENDING_KEYS];
		sort($pendingKeys);
		$isPending = $actualKeys === $pendingKeys;
		if (!$isPending && $actualKeys !== $baseKeys)
		{
			return null;
		}

		foreach (self::POSITIVE_INT_KEYS as $key)
		{
			if (!is_int($payload[$key]) || $payload[$key] <= 0)
			{
				return null;
			}
		}

		if (!is_array($payload['sourceFileIds']) || !is_array($payload['copiedObjectIds']))
		{
			return null;
		}

		$sourceFileIds = self::normalizeIds($payload['sourceFileIds']);
		$copiedObjectIds = self::normalizeIds($payload['copiedObjectIds']);
		if ($sourceFileIds === null || $copiedObjectIds === null)
		{
			return null;
		}

		$isValid = $payload['version'] === self::VERSION
			&& is_string($payload['folderName'])
			&& str_starts_with($payload['folderName'], self::FOLDER_NAME_PREFIX)
			&& is_string($payload['externalLinkHash'])
			&& $payload['externalLinkHash'] !== ''
			&& $sourceFileIds !== []
			&& $sourceFileIds === $payload['sourceFileIds']
			&& count($copiedObjectIds) === count($sourceFileIds)
			&& $copiedObjectIds === $payload['copiedObjectIds']
			&& $payload['linkObjectId'] === (
				count($copiedObjectIds) === 1
					? $copiedObjectIds[0]
					: $payload['folderId']
			);
		if (!$isValid)
		{
			return null;
		}

		$base = new self(
			$payload['userId'],
			$payload['storageId'],
			$payload['mailFolderId'],
			$payload['folderId'],
			$payload['folderName'],
			$payload['linkObjectId'],
			$payload['externalLinkId'],
			$payload['externalLinkHash'],
			$sourceFileIds,
			$copiedObjectIds,
		);
		if (!$isPending)
		{
			return $base;
		}

		$stagedObjectIds = self::normalizeIds($payload['stagedObjectIds']);
		if ($stagedObjectIds === null)
		{
			return null;
		}

		$isPendingValid = is_int($payload['stagingFolderId'])
			&& $payload['stagingFolderId'] > 0
			&& is_string($payload['stagingFolderName'])
			&& str_starts_with($payload['stagingFolderName'], self::STAGING_FOLDER_NAME_PREFIX)
			&& is_int($payload['previousExternalLinkId'])
			&& $payload['previousExternalLinkId'] > 0
			&& $stagedObjectIds !== []
			&& $stagedObjectIds === $payload['stagedObjectIds']
			&& array_diff($stagedObjectIds, $copiedObjectIds) === []
			&& count($copiedObjectIds) - count($stagedObjectIds) > 0;
		if (!$isPendingValid)
		{
			return null;
		}

		return $base->withPendingReplacement(
			$payload['stagingFolderId'],
			$payload['stagingFolderName'],
			$stagedObjectIds,
			$payload['previousExternalLinkId'],
		);
	}

	/**
	 * @param int[] $stagedObjectIds
	 */
	public function withPendingReplacement(
		int $stagingFolderId,
		string $stagingFolderName,
		array $stagedObjectIds,
		int $previousExternalLinkId,
	): self
	{
		return new self(
			$this->userId,
			$this->storageId,
			$this->mailFolderId,
			$this->folderId,
			$this->folderName,
			$this->linkObjectId,
			$this->externalLinkId,
			$this->externalLinkHash,
			$this->sourceFileIds,
			$this->copiedObjectIds,
			$stagingFolderId,
			$stagingFolderName,
			$stagedObjectIds,
			$previousExternalLinkId,
		);
	}

	public function isPendingReplacement(): bool
	{
		return $this->stagingFolderId > 0;
	}

	/**
	 * @return array<string, mixed> keys in the order the token is signed in
	 */
	public function toArray(): array
	{
		$payload = [
			'version' => self::VERSION,
			'userId' => $this->userId,
			'storageId' => $this->storageId,
			'mailFolderId' => $this->mailFolderId,
			'folderId' => $this->folderId,
			'folderName' => $this->folderName,
			'linkObjectId' => $this->linkObjectId,
			'externalLinkId' => $this->externalLinkId,
			'externalLinkHash' => $this->externalLinkHash,
			'sourceFileIds' => $this->sourceFileIds,
			'copiedObjectIds' => $this->copiedObjectIds,
		];
		if (!$this->isPendingReplacement())
		{
			return $payload;
		}

		$payload['stagingFolderId'] = $this->stagingFolderId;
		$payload['stagingFolderName'] = $this->stagingFolderName;
		$payload['stagedObjectIds'] = $this->stagedObjectIds;
		$payload['previousExternalLinkId'] = $this->previousExternalLinkId;

		return $payload;
	}

	/**
	 * Id list form of the schema: unique positive ids in ascending order. The caller normalizes a client
	 * set with the same rule before comparing it with the set of the token.
	 *
	 * @return int[]|null
	 */
	public static function normalizeIds(array $ids): ?array
	{
		$normalized = [];
		foreach ($ids as $id)
		{
			if (is_int($id))
			{
				$normalizedId = $id;
			}
			elseif (is_string($id) && preg_match('/^[0-9]+$/D', $id) === 1)
			{
				$normalizedId = (int)$id;
			}
			else
			{
				return null;
			}

			if ($normalizedId <= 0 || isset($normalized[$normalizedId]))
			{
				return null;
			}

			$normalized[$normalizedId] = $normalizedId;
		}

		$normalized = array_values($normalized);
		sort($normalized, SORT_NUMERIC);

		return $normalized;
	}
}

