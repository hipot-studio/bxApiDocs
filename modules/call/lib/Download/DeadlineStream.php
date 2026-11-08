<?php

namespace Bitrix\Call\Download;

use Bitrix\Main\Web\Http\Stream;

/**
 * A response body that refuses to grow after the deadline of its {@see DeadlineResponseBuilder}.
 *
 * @internal
 */
final class DeadlineStream extends Stream
{
	public function __construct(Stream $body, private readonly DeadlineResponseBuilder $builder)
	{
		parent::__construct($body);
	}

	public function write(string $string): int
	{
		if (!$this->builder->hasTimeLeft())
		{
			throw new \RuntimeException('The deadline of the request has passed');
		}

		return parent::write($string);
	}
}
