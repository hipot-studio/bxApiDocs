<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Debugger\DebugTrace;
use Bitrix\Bizproc\Internal\Model\Debugger\DebugTraceTable;
use Bitrix\Bizproc\Internal\Model\Debugger\EO_DebugTrace;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;

final class DebugTraceOrmMapper
{
	/**
	 * @throws ArgumentException
	 */
	public function convertFromOrm(EO_DebugTrace $ormModel): DebugTrace
	{
		return DebugTrace::mapFromArray([
			'id' => $ormModel->getId(),
			'debug_session_id' => $ormModel->getDebugSessionId(),
			'key' => $ormModel->getKey(),
			'type' => $ormModel->getType(),
			'message' => $ormModel->getMessage(),
			'data' => Json::decode($ormModel->getData()),
			'context' => Json::decode($ormModel->getContext()),
			'timestamp' => $ormModel->getTimestamp(),
		]);
	}

	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function convertToOrm(DebugTrace $entity): EO_DebugTrace
	{
		$ormModel = !$entity->isNew()
			? EO_DebugTrace::wakeUp($entity->getId())
			: DebugTraceTable::createObject();

		$ormModel
			->setDebugSessionId($entity->getDebugSessionId())
			->setKey($entity->getKey())
			->setType($entity->getType()?->value)
			->setMessage($entity->getMessage())
			->setData(Json::encode($entity->getData() ?? []))
			->setContext(Json::encode($entity->getContext() ?? []))
			->setTimestamp($entity->getTimestamp()?->getValue())
		;

		return $ormModel;
	}
}
