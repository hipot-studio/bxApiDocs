<?php

namespace Bitrix\Call\DTO;

/**
 * @internal
 */
class CloudRecordingRequest extends Hydrator
{
	public int $chatId = 0;
	public string $roomId = '';
	public ?array $recording = [];
	public ?array $preview = [];
	public string $requestId = '';

	/**
	 * Operator-driven resend from the controller admin panel: every file of the pair the portal
	 * already holds goes into processing again. A media retransmit never sets it, so a stored
	 * file keeps being skipped on the regular delivery path.
	 */
	public bool $manualRetry = false;
}
