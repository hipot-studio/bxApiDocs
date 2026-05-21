<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

use Bitrix\Crm\Activity\StatisticsMark;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;

class ClientMark extends ContentBlock
{
	protected ?string $mark = null;
	protected ?string $text = null;

	private const POSITIVE = 'positive';
	private const NEUTRAL = 'neutral';
	private const NEGATIVE = 'negative';

	public static function getByCallVote(int $callVote): ?string
	{
		return match ($callVote)
		{
			StatisticsMark::Negative => self::NEGATIVE,
			StatisticsMark::Neutral => self::NEUTRAL,
			StatisticsMark::Positive => self::POSITIVE,
			default => null,
		};
	}

	public static function getByChatVote(int $chatVote): ?string
	{
		if ($chatVote <= 0)
		{
			return null;
		}

		return match (true)
		{
			$chatVote > 3 => self::POSITIVE,
			$chatVote === 3 => self::NEUTRAL,
			$chatVote > 0 => self::NEGATIVE,
			default => null,
		};
	}

	public function getRendererName(): string
	{
		return 'ClientMark';
	}

	public function getMark(): ?string
	{
		return $this->mark;
	}

	public function setMark(?string $mark): self
	{
		$this->mark = $mark;

		return $this;
	}

	public function getText(): ?string
	{
		return $this->text;
	}

	public function setText(?string $text): self
	{
		$this->text = $text;

		return $this;
	}

	protected function getProperties(): array
	{
		return [
			'mark' => $this->getMark(),
			'text' => $this->getText(),
		];
	}
}
