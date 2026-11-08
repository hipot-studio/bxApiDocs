<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Http;

use Bitrix\Main\Web\Http\InflateStream;

/** Inflates in small slices so a compressed bomb is stopped near the limit instead of after a whole chunk. */
class BoundedInflateStream extends InflateStream
{
	private const SLICE_LENGTH = 512;

	public function __construct($stream, private readonly int $lengthMax, $mode = 'r+')
	{
		parent::__construct($stream, $mode);
	}

	public function write(string $string): int
	{
		foreach (str_split($string, self::SLICE_LENGTH) as $slice)
		{
			parent::write($slice);

			if ($this->lengthMax > 0 && $this->getSize() > $this->lengthMax)
			{
				throw new \RuntimeException('Maximum content length has been reached.');
			}
		}

		return strlen($string);
	}
}
