<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Main\Web\Json;

final class DeliveryBoundary
{
	private const VERSION = 1;

	public function __construct(
		private readonly int $generationId,
		private readonly int $uidValidity,
		private readonly int $maximumUid,
	)
	{
	}

	public static function fromStoredValue(string $value): ?self
	{
		if (preg_match('/^1:([0-9a-z]+):([0-9a-z]+):([0-9a-z]+)$/D', $value, $matches))
		{
			return self::fromValues(
				(int)base_convert($matches[1], 36, 10),
				(int)base_convert($matches[2], 36, 10),
				(int)base_convert($matches[3], 36, 10),
			);
		}

		try
		{
			$data = Json::decode($value);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (
			!is_array($data)
			|| (int)($data['version'] ?? 0) !== self::VERSION
			|| !isset($data['generationId'], $data['uidValidity'], $data['maximumUid'])
		)
		{
			return null;
		}

		$generationId = (int)$data['generationId'];
		$uidValidity = (int)$data['uidValidity'];
		$maximumUid = (int)$data['maximumUid'];

		return self::fromValues($generationId, $uidValidity, $maximumUid);
	}

	public function toStoredValue(): string
	{
		return implode(':', [
			self::VERSION,
			base_convert((string)$this->generationId, 10, 36),
			base_convert((string)$this->uidValidity, 10, 36),
			base_convert((string)$this->maximumUid, 10, 36),
		]);
	}

	public function belongsTo(int $generationId, int $uidValidity): bool
	{
		return $this->generationId === $generationId && $this->uidValidity === $uidValidity;
	}

	public function classify(int $generationId, int $uidValidity, int $uid, bool $isRecovered = false): DeliveryContext
	{
		if ($uid <= 0 || !$this->belongsTo($generationId, $uidValidity))
		{
			return DeliveryContext::unknown();
		}

		if ($uid > $this->maximumUid)
		{
			return DeliveryContext::live();
		}

		return $isRecovered ? DeliveryContext::repair() : DeliveryContext::backfill();
	}

	public function getMaximumUid(): int
	{
		return $this->maximumUid;
	}

	private static function fromValues(int $generationId, int $uidValidity, int $maximumUid): ?self
	{
		if ($generationId < 0 || $uidValidity <= 0 || $maximumUid < 0)
		{
			return null;
		}

		return new self($generationId, $uidValidity, $maximumUid);
	}
}
