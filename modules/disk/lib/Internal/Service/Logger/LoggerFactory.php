<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Logger;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Diag;
use Psr\Log\LoggerInterface;

class LoggerFactory
{
	/**
	 * @param string $id
	 * @param array $params
	 * @param array $context
	 * @param string|null $feature
	 * @param bool $shouldAddDateTime
	 * @return LoggerInterface
	 */
	public static function create(
		string $id = 'default',
		array $params = [],
		array $context = [],
		string $feature = null,
		bool $shouldAddDateTime = true,
	): LoggerInterface
	{
		$fullId = "disk.$id";
		// TODO to module options?
		$isEnabled = Configuration::getValue('loggers')[$fullId]['isEnabled'] ?? false;

		if ($isEnabled)
        {
			$logger = Diag\Logger::create(
				id: "disk.$id",
				params: $params,
			);
		} else {
			$logger = null;
		}

		if (is_string($feature))
		{
			$context['feature'] = $feature;
		}

		return new LoggerWrapper(
			logger: $logger,
			context: $context,
			shouldAddDateTime: $shouldAddDateTime,
		);
	}
}