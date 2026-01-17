<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Trait\CastTrait;
use Bitrix\Tasks\V2\Internal\Service\NameService;
use Bitrix\Tasks\Integration\Extranet;

class HistoryGridLogMapper
{
	use CastTrait;

	public function __construct(
		private readonly NameService $nameService
	)
	{

	}

	public function mapToCollection(array $logs): Entity\HistoryGridLogCollection
	{
		$entities = new Entity\HistoryGridLogCollection();
		foreach ($logs as $log)
		{
			$entities->add($this->mapToEntity($log));
		}

		return $entities;
	}

	private function mapToEntity(array $log): Entity\HistoryGridLog
	{
		$userId = (int)($log['USER_ID'] ?? 0);

		$userEntity = new Entity\User(
			id: $userId,
			name: $this->nameService->format([
				'TITLE' => (string)($log['USER_TITLE'] ?? ''),
				'NAME' => (string)($log['USER_NAME'] ?? ''),
				'LAST_NAME' => (string)($log['USER_LAST_NAME'] ?? ''),
				'SECOND_NAME' => (string)($log['USER_SECOND_NAME'] ?? ''),
				'EMAIL' => (string)($log['USER_EMAIL'] ?? ''),
				'LOGIN' => (string)($log['USER_LOGIN'] ?? ''),
				'ID' => $userId,
			]),
			type: $this->getUserType($userId),
		);

		$entityFields = [
			'id' => (int)($log['ID'] ?? 0),
			'createdDateTs' => $this->castDateTime($log['CREATED_DATE'] ?? null),
			'user' => $userEntity,
			'taskId' => (int)($log['TASK_ID'] ?? 0),
			'field' => (string)($log['FIELD'] ?? ''),
			'fromValue' => $log['FROM_VALUE'] ?? null,
			'toValue' => $log['TO_VALUE'] ?? null,
		];

		return Entity\HistoryGridLog::mapFromArray($entityFields);
	}

	private function getUserType(int $userId): Entity\User\Type
	{
		$isExtranet = Extranet\User::isExtranet($userId);
		$isCollaber = Extranet\User::isExtranet($userId) && Extranet\User::isCollaber($userId);

		if ($isCollaber)
		{
			return Entity\User\Type::Collaber;
		}

		return $isExtranet ? Entity\User\Type::Extranet : Entity\User\Type::Employee;
	}
}
