<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Image;

use Bitrix\Mail\Internals\SignatureImageTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

final class SignatureImageCreationGuard
{
	public const ERROR_LIMIT_EXCEEDED = 'SIGNATURE_IMAGE_CREATION_LIMIT_EXCEEDED';

	private const MAX_UNATTACHED_IMAGES = 50;
	private const MAX_CREATIONS_PER_MINUTE = 20;
	private const RATE_PERIOD = '-1 minute';

	private \Closure $acquireLock;
	private \Closure $releaseLock;
	private \Closure $countUnattached;
	private \Closure $countRecent;

	public function __construct(
		?\Closure $acquireLock = null,
		?\Closure $releaseLock = null,
		?\Closure $countUnattached = null,
		?\Closure $countRecent = null,
	)
	{
		$this->acquireLock = $acquireLock ?? static fn(int $ownerId): bool => Application::getConnection()->lock(
			self::getLockName($ownerId),
			5,
		);
		$this->releaseLock = $releaseLock ?? static function (int $ownerId): void {
			Application::getConnection()->unlock(self::getLockName($ownerId));
		};
		$this->countUnattached = $countUnattached ?? static fn(int $ownerId): int => SignatureImageTable::getCount([
			'=OWNER_ID' => $ownerId,
			'@STATE' => [
				SignatureImageTable::STATE_PENDING,
				SignatureImageTable::STATE_ORPHANED,
				SignatureImageTable::STATE_DELETING,
			],
		]);
		$this->countRecent = $countRecent ?? static fn(int $ownerId, DateTime $since): int => SignatureImageTable::getCount([
			'=OWNER_ID' => $ownerId,
			'>=DATE_CREATE' => $since,
		]);
	}

	public function execute(int $ownerId, \Closure $create): Result
	{
		if (!(bool)($this->acquireLock)($ownerId))
		{
			return $this->failure();
		}

		try
		{
			$since = (new DateTime())->add(self::RATE_PERIOD);
			if (
				(int)($this->countUnattached)($ownerId) >= self::MAX_UNATTACHED_IMAGES
				|| (int)($this->countRecent)($ownerId, $since) >= self::MAX_CREATIONS_PER_MINUTE
			)
			{
				return $this->failure();
			}

			return $create();
		}
		finally
		{
			($this->releaseLock)($ownerId);
		}
	}

	private static function getLockName(int $ownerId): string
	{
		return 'mail.signature-image.create.' . $ownerId;
	}

	private function failure(): Result
	{
		return (new Result())->addError(new Error(self::ERROR_LIMIT_EXCEEDED, self::ERROR_LIMIT_EXCEEDED));
	}
}
