<?php

namespace Bitrix\Main\Data\Internal\Storage;

use Bitrix\Main\Data\Storage\Exception\InvalidTtlException;
use Bitrix\Main\Type\DateTime;

class TtlValue
{
	private int $ttl;
	public function __construct(
		int|\DateInterval|null $ttl,
	)
	{
		if (is_null($ttl))
		{
			throw new InvalidTtlException('Ttl must be a positive integer');
		}

		if (is_int($ttl) && $ttl <= 0)
		{
			throw new InvalidTtlException('Ttl must be a positive integer');
		}

		if ($ttl instanceof \DateInterval && $ttl->invert === 1)
		{
			throw new InvalidTtlException('Ttl must be a positive interval');
		}

		if ($ttl instanceof \DateInterval)
		{
			$this->ttl = (new \DateTime())->add($ttl)->getTimestamp() - (new \DateTime())->getTimestamp();
		}
		else
		{
			$this->ttl = (int)$ttl;
		}
	}

	public function getTtl(): int
	{
		return $this->ttl;
	}

	public function getExpiredAt(): DateTime
	{
		return (new DateTime())->add('+' . $this->ttl . ' seconds');
	}
}
