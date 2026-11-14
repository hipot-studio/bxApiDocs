<?php

namespace Bitrix\Crm\RepeatSale\DataCollector\Activity;

final class TextLengthConfig
{
	private const DEFAULT_CONFIG = [
		'min_length' => 100,
		'max_length' => 5000,
	];

	/**
	 * The shortest text {@see TextNormalizer} accepts: a minimum of zero is not a minimum but a missing
	 * setting, and the normalizer refuses it.
	 */
	private const NO_MINIMUM = 1;

	/**
	 * @param int|null $minLength The minimum of every kind, when the caller sets one of its own. The
	 * minima of the kinds are kept as they are otherwise.
	 */
	public function __construct(private readonly ?int $minLength = null)
	{
	}

	/**
	 * The lengths of the kinds with no minimum left of them, for a caller that wants every text it asked
	 * for however short it is. The collector of the repeat sales reasons about a history and a text of
	 * three words tells it nothing, while a summary of a card shows what there is - a comment of one line
	 * is a record of its own there.
	 */
	public static function withoutMinimumLength(): self
	{
		return new self(self::NO_MINIMUM);
	}

	public function getConfigForType(ActivityType $type): array
	{
		$config = $this->lengthLimitsMap()[$type->value] ?? self::DEFAULT_CONFIG;
		if ($this->minLength !== null)
		{
			$config['min_length'] = $this->minLength;
		}

		return $config;
	}

	private function lengthLimitsMap(): array
	{
		return [
			ActivityType::CALL_RECORDING_TRANSCRIPTS->value => [
				'min_length' => 100,
				'max_length' => 20000,
			],
			ActivityType::COMMENTS->value => [
				'min_length' => 100,
				'max_length' => 5000,
			],
			ActivityType::TODOS->value => [
				'min_length' => 100,
				'max_length' => 5000,
			],
			ActivityType::EMAILS->value => [
				'min_length' => 100,
				'max_length' => 5000,
			],
			ActivityType::OPEN_LINES->value => [
				'min_length' => 100,
				'max_length' => 5000,
			],
		];
	}
}
