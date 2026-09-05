<?php
namespace Bitrix\BIConnector\Internal\Cache;

/**
 * Output-buffer sink that streams every chunk through untouched while capturing a copy
 * for the cache, up to a byte cap. Past the cap the copy is dropped and streaming continues,
 * so a large export keeps peak memory bounded by the cap instead of the full response.
 */
final class BodyCapture
{
	private string $body = '';
	private bool $capturing = true;

	public function __construct(private readonly int $maxBytes)
	{
	}

	public function sink(string $chunk): string
	{
		if ($this->capturing)
		{
			if (strlen($this->body) + strlen($chunk) > $this->maxBytes)
			{
				$this->capturing = false;
				$this->body = '';
			}
			else
			{
				$this->body .= $chunk;
			}
		}

		return $chunk;
	}

	public function isComplete(): bool
	{
		return $this->capturing;
	}

	public function getBody(): string
	{
		return $this->body;
	}
}
