<?php

namespace Bitrix\Tasks\Integration\Bizproc\Flow;

use Bitrix\Bizproc\Script\Manager as BizProcManager;
use Bitrix\Main\Web\Json;
use Bitrix\Tasks\Flow\Notification\Config\Item;
use Bitrix\Tasks\Integration\Bizproc\Flow\Robot\Factory;
use Bitrix\Tasks\Integration\Bizproc\Exception\SmartProcessException;

class Manager
{
	public function runProc(int $procId, array $docIds = []): void
	{
		if (!$procId)
		{
			return;
		}

		$userId = 1;
		$parameters = [];
		$result = BizProcManager::startScript($procId, $userId, $docIds, $parameters);

		if (!$result->isSuccess())
		{
			$message = 'Tasks\Integration\Bizproc\AutoTask:Failed running smart proc: ' . Json::encode($result->getErrorMessages());
			throw new SmartProcessException($message, $result->getData());
		}
	}

	public function deleteSmartProcess(int $procId): void
	{
		if ($procId > 0)
		{
			BizProcManager::deleteScript($procId);
		}
	}

	public function addSmartProcess(Item $item): int
	{
		$userId = 1;
		$procId = 0;
		$documentType = Factory::getDocumentType($item);
		$robots = Factory::buildRobots($item);

		if (empty($robots))
		{
			AddMessage2Log('Tasks\Integration\Bizproc\AutoTask:Unknown robot type: ' . $item->getChannel());

			return 0;
		}

		$fields = [
			'script' => [
				'ID' => $procId,
				'NAME' => $item->getCaption()->getValue(),
				'DESCRIPTION' => 'This script was automatically created by Tasks:SyncAgent',
			],
			'robotsTemplate' => [
				'ID' => 0,
				'DOCUMENT_TYPE' => $documentType,
				'DOCUMENT_STATUS' => 'SCRIPT',
				'PARAMETERS' => [],
				'CONSTANTS' => [],
				'VARIABLES' => [],
				'IS_EXTERNAL_MODIFIED' => 0,
				'ROBOTS' => $robots,
			],
		];

		$result = BizProcManager::saveScript(
			$procId,
			$documentType,
			$fields,
			$userId
		);

		if (!$result->isSuccess()) {
			$message = 'Tasks\Integration\Bizproc\AutoTask:Failed creating new smart proc: ' . Json::encode($result->getErrorMessages());
			throw new SmartProcessException($message, $result->getData());
		}

		return $result->getId();
	}
}