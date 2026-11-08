<?php

namespace Bitrix\Call\Download;

use Bitrix\Main\Web\Http\Response;
use Bitrix\Main\Web\Http\ResponseBuilder;

/**
 * Builds responses whose body stops taking bytes once the deadline has passed.
 *
 * Both handlers of HttpClient - curl and socket - write every piece of a response body through
 * the body stream of the response the builder made, and both turn a write that throws into a
 * broken transfer. So a response still arriving at the deadline ends there, whichever handler
 * reads it, and the builder remembers that it was the deadline that ended it.
 *
 * @internal Used by {@see ChunkedDownloadEngine} for the requests of the bounded mode.
 */
final class DeadlineResponseBuilder extends ResponseBuilder
{
	private bool $ranOutOfTime = false;

	/**
	 * @param \Closure(): float $clock
	 */
	public function __construct(private readonly float $deadline, private readonly \Closure $clock)
	{
	}

	public function createFromString(string $response): Response
	{
		$built = parent::createFromString($response);

		// The body may carry the dechunk or inflate filter; the new stream shares its resource.
		/** @var Response $built */
		$built = $built->withBody(new DeadlineStream($built->getBody(), $this));

		return $built;
	}

	/**
	 * Whether the deadline is still ahead; once it is not, the builder remembers it.
	 */
	public function hasTimeLeft(): bool
	{
		if (($this->clock)() < $this->deadline)
		{
			return true;
		}

		$this->ranOutOfTime = true;

		return false;
	}

	/**
	 * Whether a body write or a caller of {@see self::hasTimeLeft()} met the deadline passed.
	 */
	public function ranOutOfTime(): bool
	{
		return $this->ranOutOfTime;
	}
}
