<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Http;

use Bitrix\Main\Web\Http\Socket\Stream;

/**
 * Socket stream bounded by a wall-clock deadline and a response headers limit (headers are read line by line).
 * The remaining time is applied to every step: connect, TLS handshake, writes and reads.
 */
class BoundedSocketStream extends Stream
{
	private const HANDSHAKE_POLL_MICROSECONDS = 50000;

	private float $deadline = 0.0;
	private int $headersLengthMax = 0;
	private int $headersLength = 0;
	private int $readLength = 0;

	public function __construct(string $address, array $options = [])
	{
		parent::__construct($address, $options);

		if (($options['deadline'] ?? 0) > 0)
		{
			$this->deadline = microtime(true) + (int)$options['deadline'];
			$this->socketTimeout = min($this->socketTimeout, (int)$options['deadline']);
		}
		$this->headersLengthMax = (int)($options['headersLengthMax'] ?? 0);
	}

	/** A blocking handshake ignores the stream timeout, so the deadline is watched while it is pending. */
	public function enableCrypto(bool $enable = true)
	{
		if ($this->deadline <= 0)
		{
			return parent::enableCrypto($enable);
		}

		$this->setBlocking(false);
		try
		{
			while (($result = parent::enableCrypto($enable)) === 0)
			{
				if ($this->getRemainingTime() <= 0)
				{
					return false;
				}

				$read = [$this->resource];
				$write = null;
				$except = null;
				stream_select($read, $write, $except, 0, self::HANDSHAKE_POLL_MICROSECONDS);
			}
		}
		finally
		{
			$this->setBlocking(true);
		}

		return $result;
	}

	public function write(string $string): int
	{
		$this->limitToRemainingTime();

		return parent::write($string);
	}

	public function gets()
	{
		$this->limitToRemainingTime();

		$lengthLimit = $this->headersLengthMax > 0 ? $this->headersLengthMax - $this->headersLength + 2 : null;
		$line = fgets($this->resource, $lengthLimit);

		if ($line !== false)
		{
			if ($this->streamTimeout > 0)
			{
				$this->lastTime = time();
			}

			$this->headersLength += strlen($line);
			if ($this->headersLengthMax > 0 && $this->headersLength > $this->headersLengthMax)
			{
				throw new \RuntimeException('Maximum response headers length has been reached.');
			}
		}

		if ($this->timedOut())
		{
			throw new \RuntimeException('Stream reading timeout has been reached.');
		}

		return $line;
	}

	public function read(int $length): string
	{
		$this->limitToRemainingTime();

		$result = parent::read($length);
		$this->readLength += strlen($result);

		return $result;
	}

	public function getReadLength(): int
	{
		return $this->readLength;
	}

	public function timedOut(): bool
	{
		return ($this->deadline > 0 && $this->getRemainingTime() <= 0) || parent::timedOut();
	}

	private function getRemainingTime(): float
	{
		return $this->deadline - microtime(true);
	}

	private function limitToRemainingTime(): void
	{
		if ($this->deadline <= 0)
		{
			return;
		}

		$remaining = $this->getRemainingTime();
		if ($remaining <= 0)
		{
			throw new \RuntimeException('Request deadline has been reached.');
		}

		$seconds = (int)$remaining;
		stream_set_timeout($this->resource, $seconds, (int)(($remaining - $seconds) * 1000000));
	}
}
