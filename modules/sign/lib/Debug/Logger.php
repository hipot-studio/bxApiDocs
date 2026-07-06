<?php

namespace Bitrix\Sign\Debug;

use Bitrix\Main\Diag;
use Psr\Log;

/**
 * Decorator on top of a PSR-3 logger. Adds non-PSR-3 methods dump()/trace().
 * Log level threshold and formatter are owned by the inner logger.
 */
class Logger extends Diag\Logger implements Log\LoggerAwareInterface
{
	use Log\LoggerAwareTrait;

	public function __construct(?Log\LoggerInterface $logger = null)
	{
		if ($logger)
		{
			$this->setLogger($logger);
		}
	}

	public function log($level, string|\Stringable $message, array $context = []): void
	{
		$this->logger?->log($level, $message, $context);
	}

	public function dump(mixed $dump, string|\Stringable $message = ''): void
	{
		$placeholder = '{' . SecretMaskingFormatter::PLACEHOLDER_DUMP . '}';
		$text = $message !== '' ? ($message . ' ' . $placeholder) : $placeholder;
		$this->debug($text, [SecretMaskingFormatter::PLACEHOLDER_DUMP => $dump]);
	}

	public function trace(string|\Stringable $message = ''): void
	{
		$text = $message !== '' ? ($message . ' {trace}') : '{trace}';
		$this->debug($text, ['trace' => debug_backtrace()]);
	}

	protected function logMessage(string $level, string $message) {}
}
