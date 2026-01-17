<?php

namespace Bitrix\Crm\Stage;

use Generator;

final class DefaultProcessColorGenerator
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

	private readonly Generator $generator;

	public function __construct()
	{
		$this->generator = $this->generator();
	}

	public function generate(): string
	{
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
