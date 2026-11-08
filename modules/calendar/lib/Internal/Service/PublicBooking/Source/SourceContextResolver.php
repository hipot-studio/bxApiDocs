<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\PublicBooking\Source;

/**
 * Registry of BookingSourceInterface strategies.
 *
 * Iterates registered strategies in order and returns the result of the first
 * one that supports the given descriptor. No matching strategy is not an error.
 */
final class SourceContextResolver
{
	/**
	 * @param BookingSourceInterface[] $sources
	 */
	public function __construct(
		private readonly array $sources,
	)
	{
	}

	public function resolve(SourceDescriptor $descriptor): ?TrustedBookingConfig
	{
		foreach ($this->sources as $source)
		{
			if ($source->supports($descriptor))
			{
				return $source->resolve($descriptor);
			}
		}

		return null;
	}
}
