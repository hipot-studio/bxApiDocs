<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\PublicBooking\Source;

/**
 * Strategy interface for resolving a trusted booking configuration from a source descriptor.
 *
 * Each implementation handles a single source type (e.g. 'crm-webform').
 * Adding a new source = adding a new implementation, without modifying the resolver.
 */
interface BookingSourceInterface
{
	public function supports(SourceDescriptor $descriptor): bool;

	public function resolve(SourceDescriptor $descriptor): ?TrustedBookingConfig;
}
