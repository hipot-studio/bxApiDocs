<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Exception;

use Bitrix\AiAssistant\Exceptions\ValidationException;

final class DownloadFileMcpException extends ValidationException
{
	public function __construct(string $machineCode)
	{
		parent::__construct($machineCode);
	}
}
