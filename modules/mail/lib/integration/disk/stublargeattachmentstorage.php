<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Fallback storage used until the disk contract (MR 7258) is merged.
 *
 * Every operation returns a controlled "disk unavailable" error. This is an expected mode, not a failure:
 * the stub never throws a fatal and never logs as an error.
 */
final class StubLargeAttachmentStorage implements LargeAttachmentStorageInterface
{
	public function getMailAttachmentsFolder(int $userId): Result
	{
		return $this->diskUnavailable();
	}

	public function uploadAndLink(int $userId, array $diskFileIds): Result
	{
		return $this->diskUnavailable();
	}

	public function extendAndLink(int $userId, string $token, array $diskFileIds): Result
	{
		return $this->diskUnavailable();
	}

	public function finalizeReplacement(int $userId, string $previousToken, string $currentToken): Result
	{
		return $this->diskUnavailable();
	}

	public function resolveForSend(int $userId, string $token, array $diskFileIds): Result
	{
		return $this->diskUnavailable();
	}

	public function deleteUploaded(int $userId, string $token): Result
	{
		return $this->diskUnavailable();
	}

	private function diskUnavailable(): Result
	{
		$result = new Result();

		return $result->addError(new Error(
			'Large attachment disk API is unavailable.',
			self::ERROR_DISK_UNAVAILABLE,
		));
	}
}
