<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\SystemHistoryLog;
use Bitrix\Tasks\V2\Internal\Entity\SystemHistoryLogCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Trait\CastTrait;
use Bitrix\Tasks\V2\Internal\Service\SystemHistoryLog\ErrorsUnpackService;

class SystemHistoryLogMapper
{
	use CastTrait;

	public function __construct(
		private readonly ErrorsUnpackService $errorsUnpackService,
	)
	{

	}

	public function mapToEntity(array $log): SystemHistoryLog
	{
		return SystemHistoryLog::mapFromArray([
			'id' => (int)($log['ID'] ?? 0),
			'type' => (int)($log['TYPE'] ?? 0),
			'createdDateTs' => $this->castDateTime($log['CREATED_DATE'] ?? null),
			'message' => (string)($log['MESSAGE'] ?? ''),
			'errors' => $this->errorsUnpackService->unpackErrors((string)($log['ERROR'] ?? '')),
		]);
	}

	public function mapToCollection(array $historyGridLogs): SystemHistoryLogCollection
	{
		$historyGridLogsCollection = new SystemHistoryLogCollection();

		foreach ($historyGridLogs as $historyGridLog)
		{
			$historyGridLogsCollection->add($this->mapToEntity($historyGridLog));
		}

		return $historyGridLogsCollection;
	}
}
