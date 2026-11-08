<?php

namespace Bitrix\Call\Track\Downloader;

use Bitrix\Call\Download\DownloadJob;
use Bitrix\Call\Download\DownloadPolicy;
use Bitrix\Call\Track;

/**
 * A call track as a job of {@see \Bitrix\Call\Download\ChunkedDownloadEngine}.
 *
 * The expected size is the one the factory probed, not the row field: the chunked downloader has
 * always been driven by it.
 *
 * @internal
 */
final class TrackDownloadJob implements DownloadJob
{
	public const KEY_PREFIX = 'call.track:';

	public function __construct(
		private readonly Track $track,
		private readonly int $expectedSize,
		private readonly DownloadPolicy $policy,
	)
	{
	}

	public function getTrack(): Track
	{
		return $this->track;
	}

	public function getKey(): string
	{
		return self::KEY_PREFIX . (int)$this->track->getId();
	}

	public function getUrl(): string
	{
		return (string)$this->track->getDownloadUrl();
	}

	public function getExpectedSize(): int
	{
		return $this->expectedSize;
	}

	public function getTempPath(): string
	{
		return (string)$this->track->getTempPath();
	}

	public function getPolicy(): DownloadPolicy
	{
		return $this->policy;
	}
}
