<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Main\Type\RandomSequence;

class NodeIdGenerator
{
	private const LENGTH = 6;

	public function generate(string $seed): string
	{
		return (new RandomSequence($seed))->randString(self::LENGTH);
	}
}
