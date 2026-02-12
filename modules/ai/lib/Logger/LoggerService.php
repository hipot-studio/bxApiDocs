<?php declare(strict_types=1);

namespace Bitrix\AI\Logger;

use Bitrix\Main\Application;

class LoggerService
{
	protected string $host;

	public function __construct()
	{
		$this->host = Application::getInstance()->getContext()->getServer()->getHttpHost();
	}

	public function logMessage(string $errorCode, string $message): void
	{
		AddMessage2Log("{$this->host} {$errorCode} {$message}", 'ai');
	}
}
