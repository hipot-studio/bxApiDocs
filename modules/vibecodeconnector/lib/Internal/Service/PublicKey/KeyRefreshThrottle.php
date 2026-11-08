<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

/**
 * Limits how often the portal may go out for a public key: cloud-shared or pairing.
 *
 * The key is fetched from an unauthenticated request path, so a flood of invalid
 * tokens must not turn into a flood of outgoing requests. The mark is stored in
 * an option (not in cache): pairing mutations wipe whole cache directories.
 */
final class KeyRefreshThrottle
{
	private const OPTION_NAME = 'cloud_shared_key_attempt_at';
	private const WINDOW_SECONDS = 60;

	public function __construct(
		private readonly ModuleOptions $options = new ModuleOptions(),
		private readonly string $optionName = self::OPTION_NAME,
	) {
	}

	public function isAllowed(?int $now = null): bool
	{
		$now ??= time();
		$lastAttempt = (int)$this->options->get($this->optionName, '0');

		// A mark from the future means the clock moved back: do not block forever.
		if ($lastAttempt > $now)
		{
			return true;
		}

		return ($now - $lastAttempt) >= self::WINDOW_SECONDS;
	}

	public function markAttempt(?int $now = null): void
	{
		$this->options->set($this->optionName, (string)($now ?? time()));
	}
}
