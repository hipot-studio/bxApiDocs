<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Http\Socket;

use Bitrix\Disk\Document\Flipchart\Http\RequestDeadline;
use Bitrix\Main\Web\Http;

final class DeadlineStream extends Http\Socket\Stream
{
	private string $lineBuffer = '';

	public function __construct(
		string $address,
		array $options,
		private readonly RequestDeadline $deadline,
	)
	{
		parent::__construct($address, $options);
	}

	public function connect(): void
	{
		$this->socketTimeout = min(
			$this->socketTimeout,
			max(1, (int)ceil($this->deadline->getRemainingMilliseconds() / 1000)),
		);
		parent::connect();
		$this->applyRemainingTimeout();
		$this->deadline->getRemainingMicroseconds();
	}

	public function gets(): false|string
	{
		while (($lineEnd = strpos($this->lineBuffer, "\n")) === false)
		{
			if (parent::eof())
			{
				if ($this->lineBuffer === '')
				{
					return false;
				}

				$line = $this->lineBuffer;
				$this->lineBuffer = '';

				return $line;
			}

			$this->waitForReadable();
			$chunk = $this->readNonBlocking(8192);
			if ($chunk !== '')
			{
				$this->lineBuffer .= $chunk;
			}
		}

		$line = substr($this->lineBuffer, 0, $lineEnd + 1);
		$this->lineBuffer = substr($this->lineBuffer, $lineEnd + 1);

		return $line;
	}

	public function eof(): bool
	{
		return $this->lineBuffer === '' && parent::eof();
	}

	public function read(int $length): string
	{
		if ($length <= 0)
		{
			return '';
		}
		if ($this->lineBuffer !== '')
		{
			$result = substr($this->lineBuffer, 0, $length);
			$this->lineBuffer = substr($this->lineBuffer, strlen($result));

			return $result;
		}
		if ($this->eof())
		{
			return '';
		}

		$this->waitForReadable();

		return $this->readNonBlocking($length);
	}

	public function write(string $string): int
	{
		$this->applyRemainingTimeout();
		try
		{
			return parent::write($string);
		}
		finally
		{
			$this->deadline->getRemainingMicroseconds();
		}
	}

	public function enableCrypto(bool $enable = true): bool|int
	{
		$this->applyRemainingTimeout();
		try
		{
			return parent::enableCrypto($enable);
		}
		finally
		{
			$this->deadline->getRemainingMicroseconds();
		}
	}

	private function waitForReadable(): void
	{
		$remaining = $this->deadline->getRemainingMicroseconds();
		$seconds = intdiv($remaining, 1_000_000);
		$microseconds = $remaining % 1_000_000;
		$read = [$this->resource];
		$write = null;
		$except = null;
		$result = stream_select($read, $write, $except, $seconds, $microseconds);
		if ($result === 0)
		{
			$this->deadline->markExceeded();
			throw new \RuntimeException('Board indexing request deadline has been reached.');
		}
		if ($result === false)
		{
			throw new \RuntimeException('Error waiting for the board indexing response stream.');
		}
	}

	private function readNonBlocking(int $length): string
	{
		stream_set_blocking($this->resource, false);
		try
		{
			$result = fread($this->resource, $length);
		}
		finally
		{
			stream_set_blocking($this->resource, true);
		}

		$this->deadline->getRemainingMicroseconds();

		return $result === false ? '' : $result;
	}

	private function applyRemainingTimeout(): void
	{
		$remaining = $this->deadline->getRemainingMicroseconds();
		stream_set_timeout(
			$this->resource,
			intdiv($remaining, 1_000_000),
			$remaining % 1_000_000,
		);
	}
}
