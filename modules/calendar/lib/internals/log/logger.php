<?php

namespace Bitrix\Calendar\Internals\Log;

use Bitrix\Calendar\Synchronization\Internal\Service\Logger\RequestLogger;
use Throwable;

class Logger
{
	private const DEFAULT_MARKER = 'DEBUG_CALENDAR';
	private const MODULE_ID = 'calendar';

	public function __construct(private string $marker = self::DEFAULT_MARKER)
	{
	}

	public function log(mixed $data, int $traceDepth = 6): void
	{
		if ($data instanceof Throwable)
		{
			$data = $data->getMessage();
		}
		elseif (!is_scalar($data))
		{
			$data = var_export($data, true);
		}

		try
		{
			(new RequestLogger())
				->debug((string)$data, [
					'marker' => $this->marker,
					'traceDepth' => $traceDepth,
				])
			;
		}
		catch (Throwable)
		{
		}
	}
}
