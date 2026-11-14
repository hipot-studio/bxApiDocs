<?php

namespace Bitrix\Call\DTO;

/**
 * @internal
 */
class TrackFileRequest extends Hydrator
{
	public string $callUuid = '';
	public int $trackId = 0;
	public string $type = '';
	public string $name = '';
	public string $url = '';
	public string $mime = '';
	public int $size = 0;
	public int $duration = 0;

	/**
	 * Operator-driven resend from the controller admin panel: process the event even if the
	 * track is already stored. A media retransmit never sets it, so the duplicate guard
	 * keeps working for the regular delivery path.
	 */
	public bool $manualRetry = false;

	/**
	 * The controller sends it with every callback. Read only to give an operator-driven resend
	 * its own idempotency key: the regular delivery keeps being keyed by the track id
	 * {@see \Bitrix\Call\Controller\Filter\UniqueRequestFilter}.
	 */
	public string $requestId = '';
}
