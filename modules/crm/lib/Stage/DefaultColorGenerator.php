<?php

declare(strict_types=1);

namespace Bitrix\Crm\Stage;

use Bitrix\Crm\PhaseSemantics;
use Generator;

final readonly class DefaultColorGenerator
{
	public const START_COLORS = [
		'#1F86FF',
		'#30AFFF',
		'#00C0D5',
		'#31C469',
		'#FAAA08',
	];

	public const LOOPED_COLORS = [
		'#00c4fb',
		'#47d1e2',
		'#75d900',
		'#ffab00',
		'#ff5752',
		'#468ee5',
		'#1eae43',
	];

	public const SUCCESS_COLOR = '#7BD500';
	public const FAILURE_COLOR = '#FF5752';

	private Generator $generator;

	public function __construct()
	{
		$this->generator = $this->generator();
	}

	public function generate(?string $semantics = null): string
	{
		if ($semantics === PhaseSemantics::SUCCESS)
		{
			return self::SUCCESS_COLOR;
		}

		if ($semantics === PhaseSemantics::FAILURE)
		{
			return self::FAILURE_COLOR;
		}

		$color = $this->generator->current();
		$this->generator->next();

		return $color;
	}

	private function generator(): Generator
	{
		foreach (self::START_COLORS as $color)
		{
			yield $color;
		}

		while (true)
		{
			foreach (self::LOOPED_COLORS as $color)
			{
				yield $color;
			}
		}
	}
}
