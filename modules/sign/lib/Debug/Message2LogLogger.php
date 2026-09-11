<?php

namespace Bitrix\Sign\Debug;

use Bitrix\Main\Diag\Logger as BaseLogger;

class Message2LogLogger extends BaseLogger
{
	public function __construct(
		private readonly string $loggerId = '',
		private readonly int $traceDepth = 0,
	)
	{
	}

	protected function logMessage(string $level, string $message)
	{
		$prefix = $this->loggerId !== '' ? ($this->loggerId . ' ') : '';
		\AddMessage2Log("{$prefix}{$level} {$message}", 'sign', $this->traceDepth);
	}
}
