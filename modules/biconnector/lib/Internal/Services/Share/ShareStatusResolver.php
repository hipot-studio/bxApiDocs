<?php

namespace Bitrix\BIConnector\Internal\Services\Share;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\Main\Type\DateTime;

/**
 * Computes the status of a public link from its stored state. The share right of every author
 * is checked once per resolver.
 */
class ShareStatusResolver
{
	public const STATUS_ACTIVE = 'ACTIVE';
	public const STATUS_DISABLED = 'DISABLED';
	public const STATUS_NO_RIGHT = 'NO_RIGHT';
	public const STATUS_EXPIRED = 'EXPIRED';

	/** @var array<int, bool> */
	private array $canShareByAuthor = [];

	/**
	 * @param \Closure(int): bool|null $shareRightChecker Tells whether the author may share dashboards.
	 */
	public function __construct(private readonly ?\Closure $shareRightChecker = null)
	{
	}

	/**
	 * @return string[]
	 */
	public static function getStatuses(): array
	{
		return [
			self::STATUS_ACTIVE,
			self::STATUS_DISABLED,
			self::STATUS_NO_RIGHT,
			self::STATUS_EXPIRED,
		];
	}

	/**
	 * Who switched the link off is not part of the status: the registry shows it in a column of its own.
	 */
	public function resolve(bool $isActive, int $authorId, DateTime $dateExpire): string
	{
		if (!$isActive)
		{
			return self::STATUS_DISABLED;
		}

		if ($dateExpire->getTimestamp() < time())
		{
			return self::STATUS_EXPIRED;
		}

		return $this->canAuthorShare($authorId) ? self::STATUS_ACTIVE : self::STATUS_NO_RIGHT;
	}

	public function canAuthorShare(int $authorId): bool
	{
		if (!isset($this->canShareByAuthor[$authorId]))
		{
			$this->canShareByAuthor[$authorId] = $this->shareRightChecker
				? (bool)($this->shareRightChecker)($authorId)
				: AccessController::getInstance($authorId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_SHARE)
			;
		}

		return $this->canShareByAuthor[$authorId];
	}
}
