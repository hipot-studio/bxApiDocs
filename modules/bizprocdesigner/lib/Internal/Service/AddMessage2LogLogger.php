<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Diag\Logger;

class AddMessage2LogLogger extends Logger
{
	public function __construct(
		private readonly string $loggerId = '',
		private readonly int $traceDepthLevel = 0,
	)
	{
	}

	protected function logMessage(string $level, string $message): void
	{
		$host = Application::getInstance()->getContext()->getServer()->getHttpHost();
		$loggerId = $this->loggerId ? ($this->loggerId . ' ') : '';
		AddMessage2Log("{$loggerId}{$host} {$level} {$message}", 'bizprocdesigner', $this->traceDepthLevel);
	}
}