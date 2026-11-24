<?php

namespace Bitrix\AI\Image\Url;

use Bitrix\Main\Web\Http\Socket\Stream;

final class DeadlineSocketStream extends Stream
{
	private const MAX_RESPONSE_HEADERS_LENGTH = 65_536;

	private string $readBuffer = '';
	private int $receivedHeaderBytes = 0;
	private string $peerAddress = '';

	public function __construct(string $address, array $options, private readonly int $deadline)
	{
		parent::__construct($address, $options);
	}

	public function connect(): void
	{
		$this->socketTimeout = min($this->socketTimeout, $this->getRemainingSeconds());
		parent::connect();
		$peerName = stream_socket_get_name($this->resource, true);
		if (is_string($peerName))
		{
			$separator = strrpos($peerName, ':');
			$address = $separator === false ? $peerName : substr($peerName, 0, $separator);
			if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
			{
				$this->peerAddress = $address;
			}
		}
		$this->prepareOperation();
	}

	public function getPeerAddress(): string
	{
		return $this->peerAddress;
	}

	public function gets()
	{
		while (($lineEnd = strpos($this->readBuffer, "\n")) === false)
		{
			if (parent::eof())
			{
				return $this->flushReadBuffer();
			}

			$chunk = $this->readChunk(8192);
			if ($chunk === '')
			{
				continue;
			}

			$this->readBuffer .= $chunk;
			$this->assertHeaderLength(strlen($this->readBuffer));
		}

		$line = substr($this->readBuffer, 0, $lineEnd + 1);
		$this->readBuffer = substr($this->readBuffer, $lineEnd + 1);
		$this->receivedHeaderBytes += strlen($line);
		$this->assertHeaderLength();
		if ($line === "\r\n")
		{
			$this->receivedHeaderBytes = 0;
		}

		return $line;
	}

	public function eof(): bool
	{
		return $this->readBuffer === '' && parent::eof();
	}

	public function read(int $length): string
	{
		if ($this->readBuffer !== '')
		{
			$result = substr($this->readBuffer, 0, $length);
			$this->readBuffer = substr($this->readBuffer, strlen($result));

			return $result;
		}

		return $this->readChunk($length);
	}

	public function write(string $string): int
	{
		$this->prepareOperation();
		$result = parent::write($string);
		$this->assertDeadline();

		return $result;
	}

	public function enableCrypto(bool $enable = true)
	{
		$wasBlocking = (bool)$this->getMetadata('blocked');
		$this->setBlocking(false);

		try
		{
			do
			{
				$this->assertDeadline();
				$result = parent::enableCrypto($enable);
				$this->assertDeadline();
				if ($result === 0)
				{
					$this->waitForReadableData();
				}
			}
			while ($result === 0);
		}
		finally
		{
			$this->setBlocking($wasBlocking);
		}

		return $result;
	}

	private function readChunk(int $length): string
	{
		$this->waitForReadableData();
		$wasBlocking = (bool)$this->getMetadata('blocked');
		$this->setBlocking(false);

		try
		{
			$result = parent::read($length);
		}
		finally
		{
			$this->setBlocking($wasBlocking);
		}

		$this->assertDeadline();
		if ($result !== '' && $this->streamTimeout > 0)
		{
			$this->lastTime = time();
		}

		return $result;
	}

	private function waitForReadableData(): void
	{
		$remainingNanoseconds = $this->getRemainingNanoseconds();
		$seconds = intdiv($remainingNanoseconds, 1_000_000_000);
		$microseconds = (int)ceil(($remainingNanoseconds % 1_000_000_000) / 1000);
		if ($microseconds === 1_000_000)
		{
			$seconds++;
			$microseconds = 0;
		}

		$read = [$this->resource];
		$write = [];
		$except = [];
		$result = stream_select($read, $write, $except, $seconds, $microseconds);
		if ($result === false)
		{
			throw new \RuntimeException('Error waiting for response data.');
		}
		if ($result === 0)
		{
			throw new \RuntimeException('Total request timeout has been reached.');
		}
	}

	private function flushReadBuffer(): false|string
	{
		if ($this->readBuffer === '')
		{
			return false;
		}

		$result = $this->readBuffer;
		$this->readBuffer = '';

		return $result;
	}

	private function assertHeaderLength(int $pendingBytes = 0): void
	{
		if ($this->receivedHeaderBytes + $pendingBytes > self::MAX_RESPONSE_HEADERS_LENGTH)
		{
			throw new \RuntimeException('Maximum response headers length has been reached.');
		}
	}

	private function prepareOperation(): void
	{
		$remainingSeconds = $this->getRemainingSeconds();
		if (is_resource($this->resource))
		{
			stream_set_timeout($this->resource, $remainingSeconds);
		}
	}

	private function getRemainingSeconds(): int
	{
		return max(1, (int)ceil($this->getRemainingNanoseconds() / 1_000_000_000));
	}

	private function assertDeadline(): void
	{
		$this->getRemainingNanoseconds();
	}

	private function getRemainingNanoseconds(): int
	{
		$remainingNanoseconds = $this->deadline - hrtime(true);
		if ($remainingNanoseconds <= 0)
		{
			throw new \RuntimeException('Total request timeout has been reached.');
		}

		return $remainingNanoseconds;
	}
}
