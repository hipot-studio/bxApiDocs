<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Http;

use Bitrix\Main\Web\Http\Stream;

/**
 * Follows the chunk framing of the written data to tell whether the last chunk has arrived.
 * Wraps the outermost stream of the body and writes through it: the dechunk filter itself stays on the shared resource.
 */
class ChunkedFramingStream extends Stream
{
	private string $pending = '';
	private int $chunkRemaining = 0;
	private bool $complete = false;

	public function __construct(private readonly Stream $body)
	{
		parent::__construct($body);
	}

	public function write(string $string): int
	{
		$this->follow($string);

		return $this->body->write($string);
	}

	public function isComplete(): bool
	{
		return $this->complete;
	}

	private function follow(string $data): void
	{
		if ($this->complete)
		{
			return;
		}

		$this->pending .= $data;
		while ($this->pending !== '')
		{
			if ($this->chunkRemaining > 0)
			{
				$consumed = min($this->chunkRemaining, strlen($this->pending));
				$this->pending = substr($this->pending, $consumed);
				$this->chunkRemaining -= $consumed;
				continue;
			}

			$lineEnd = strpos($this->pending, "\n");
			if ($lineEnd === false)
			{
				return;
			}

			$line = trim(substr($this->pending, 0, $lineEnd));
			$this->pending = substr($this->pending, $lineEnd + 1);
			if ($line === '')
			{
				continue;
			}

			$size = (int)hexdec(explode(';', $line, 2)[0]);
			if ($size === 0)
			{
				$this->complete = true;
				$this->pending = '';

				return;
			}
			$this->chunkRemaining = $size;
		}
	}
}
