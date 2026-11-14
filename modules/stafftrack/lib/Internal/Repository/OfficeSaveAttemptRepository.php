<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;

/**
 * Attempts to save the card of an office, one record per office, author and key of the attempt, kept
 * for a day in the short-lived storage of the core. The default storage writes through the default
 * connection, so a record saved inside the transaction of the card goes back with it.
 *
 * Failures of the storage are not caught here: whether one refuses the edit is up to the caller.
 */
class OfficeSaveAttemptRepository
{
	private const KEY_PREFIX = 'stafftrack.office.update.';

	/** A day: expired records are removed by the agent of the core. */
	private const TTL = 86400;

	private readonly StorageInterface $storage;

	public function __construct(?StorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * A record without the fingerprint of a change of the staff names no such change.
	 *
	 * @return array{
	 *     writtenVersion: string,
	 *     photoTokenHashes: string[],
	 *     removedPhotoIds: int[],
	 *     photoErrors: Error[]|null,
	 *     staffFingerprint: string,
	 * }|null null when this attempt has written nothing or its record has expired
	 */
	public function find(int $officeId, int $userId, string $key): ?array
	{
		$value = $this->storage->get($this->buildStorageKey($officeId, $userId, $key));
		if (!is_array($value))
		{
			return null;
		}

		$photoErrors = $value['photoErrors'] ?? null;

		return [
			'writtenVersion' => (string)($value['writtenVersion'] ?? ''),
			'photoTokenHashes' => array_map('strval', (array)($value['photoTokenHashes'] ?? [])),
			'removedPhotoIds' => array_map('intval', (array)($value['removedPhotoIds'] ?? [])),
			'photoErrors' => is_array($photoErrors) ? $this->restoreErrors($photoErrors) : null,
			'staffFingerprint' => (string)($value['staffFingerprint'] ?? ''),
		];
	}

	/**
	 * @param array{
	 *     writtenVersion: string,
	 *     photoTokenHashes: string[],
	 *     removedPhotoIds: int[],
	 *     photoErrors: Error[]|null,
	 *     staffFingerprint?: string,
	 * } $record photoErrors is null while the photos of the attempt are not done; staffFingerprint is the
	 *   last change of the staff the attempt applied, empty or missing when it applied none
	 */
	public function save(int $officeId, int $userId, string $key, array $record): void
	{
		$photoErrors = $record['photoErrors'] ?? null;

		$this->storage->set(
			$this->buildStorageKey($officeId, $userId, $key),
			[
				'writtenVersion' => $record['writtenVersion'],
				'photoTokenHashes' => array_values($record['photoTokenHashes']),
				'removedPhotoIds' => array_values($record['removedPhotoIds']),
				'photoErrors' => $photoErrors === null ? null : $this->describeErrors($photoErrors),
				'staffFingerprint' => $record['staffFingerprint'] ?? '',
			],
			self::TTL,
		);
	}

	/**
	 * A record that expired meanwhile is not brought back by its report.
	 *
	 * @param Error[] $photoErrors
	 */
	public function savePhotoReport(int $officeId, int $userId, string $key, array $photoErrors): void
	{
		$record = $this->find($officeId, $userId, $key);
		if ($record === null)
		{
			return;
		}

		$record['photoErrors'] = $photoErrors;

		$this->save($officeId, $userId, $key, $record);
	}

	private function buildStorageKey(int $officeId, int $userId, string $key): string
	{
		return self::KEY_PREFIX . $officeId . '.' . $userId . '.' . $key;
	}

	/**
	 * @param Error[] $errors
	 * @return array<int, array{code: string, message: string}>
	 */
	private function describeErrors(array $errors): array
	{
		return array_map(
			static fn (Error $error): array => [
				'code' => (string)$error->getCode(),
				'message' => $error->getMessage(),
			],
			array_values($errors),
		);
	}

	/**
	 * @return Error[]
	 */
	private function restoreErrors(array $described): array
	{
		$errors = [];

		foreach ($described as $error)
		{
			if (is_array($error))
			{
				$errors[] = new Error((string)($error['message'] ?? ''), (string)($error['code'] ?? ''));
			}
		}

		return $errors;
	}
}
