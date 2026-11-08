<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Service\Logger\LoggerFactory;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

final class WriteFailureLogger
{
	private readonly LoggerInterface $logger;

	public function __construct(?LoggerInterface $logger = null)
	{
		$this->logger = $logger ?? LoggerFactory::create('field-requirement-write');
	}

	public function log(
		string $operation,
		string $step,
		StorageKey $key,
		int $ordinal,
		Result $result,
	): void
	{
		$this->logger->error(
			'CRM field requirement write failed.',
			[
				'operation' => $operation,
				'step' => $step,
				'key' => $key->toArray(),
				'ordinal' => $ordinal,
				'ormCodes' => array_values(array_map(
					static fn($error): string => (string)$error->getCode(),
					$result->getErrors(),
				)),
			],
		);
	}
}
