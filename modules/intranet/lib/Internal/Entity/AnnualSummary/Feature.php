<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

use Bitrix\Intranet\Internal\Integration\Main\AnnualSummarySign;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\Contract\Arrayable;

class Feature implements Arrayable, EntityInterface
{
	public function __construct(
		private readonly FeatureType $id,
		private readonly int $count,
		private readonly int $min,
		private readonly int $max,
		private readonly int $randomVariation = 1,
		private readonly int $countVariation = 1,
	) {
	}

	protected static function generateRandomVariation(int $totalVariationsCount): int
	{
		if ($totalVariationsCount <= 1)
		{
			return 1;
		}

		try
		{
			return Random::getInt(1, $totalVariationsCount);
		}
		catch (\Exception)
		{
			return 1;
		}
	}

	protected static function generateCountVariation(array $countVariationList, int $count): int
	{
		$result = 0;

		foreach ($countVariationList as $id => $value)
		{
			if ($value < $count && $value > $countVariationList[$result])
			{
				$result = $id;
			}
		}

		return $result + 1;
	}

	public function getRate(): float
	{
		return $this->count / $this->max;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id->value,
			'signedId' => $this->signedId(),
			'count' => $this->count,
			'rate' => $this->getRate(),
			'min' => $this->min,
			'max' => $this->max,
			'randomVariation' => $this->randomVariation,
			'countVariation' => $this->countVariation,
			'message' => $this->getMessage(),
		];
	}

	public function getCount(): int
	{
		return $this->count;
	}

	public function getId(): FeatureType
	{
		return $this->id;
	}

	public function isMoreThenMin(): bool
	{
		return $this->count >= $this->min;
	}

	/**
	 * @throws ArgumentTypeException
	 */
	public function signedId(): string
	{
		$signer = new AnnualSummarySign();

		return $signer->sign($this->id->value);
	}

	public function getTitle(): string
	{
		$feature = mb_strtoupper($this->id->value);
		$code = "INTRANET_ANNUAL_SUMMARY_CARD_TITLE_{$feature}_COUNT_{$this->countVariation}_VARIETY_{$this->randomVariation}";
		if (!Loc::getMessage($code))
		{
			$code = "INTRANET_ANNUAL_SUMMARY_CARD_TITLE_{$feature}_COUNT_{$this->countVariation}_VARIETY_1";

			if (!Loc::getMessage($code))
			{
				$code = "INTRANET_ANNUAL_SUMMARY_CARD_TITLE_{$feature}_COUNT_1_VARIETY_1";
			}
		}

		return Loc::getMessage($code) ?? '';
	}

	public function getDescription(): string
	{
		$feature = mb_strtoupper($this->id->value);
		$code = "INTRANET_ANNUAL_SUMMARY_CARD_DESCRIPTION_{$feature}_COUNT_{$this->countVariation}_VARIETY_{$this->randomVariation}";

		if (!Loc::getMessage("{$code}_PLURAL_1"))
		{
			$code = "INTRANET_ANNUAL_SUMMARY_CARD_DESCRIPTION_{$feature}_COUNT_{$this->countVariation}_VARIETY_1";

			if (!Loc::getMessage("{$code}_PLURAL_1"))
			{
				$code = "INTRANET_ANNUAL_SUMMARY_CARD_DESCRIPTION_{$feature}_COUNT_1_VARIETY_1";
			}
		}

		return Loc::getMessagePlural(
			$code,
			$this->count,
			[
				'#COUNT#' => $this->count,
			],
		) ?? '';
	}

	public function getMessage(): array
	{
		return [
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
		];
	}
}
