<?php

namespace Bitrix\StaffTrack\Infrastructure\Agent\CheckIn;

use Bitrix\StaffTrack\Public\Services\AddressResolverService;

/**
 * The two names the kernel drains the address queue by. Nothing but the names lives here: the request
 * that queued the points drains the very same queue, and it cannot reach into this layer, so the work
 * itself belongs to {@see AddressResolverService}.
 *
 * The names are held in b_agent of every portal, so neither the class nor the methods may be renamed
 * without a migration of the rows.
 */
class AddressResolveAgent
{
	public static function run(): string
	{
		return (new AddressResolverService())->runFallbackQueuePass();
	}

	public static function runContinuation(): string
	{
		return (new AddressResolverService())->runContinuationQueuePass();
	}
}
