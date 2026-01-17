<?php

namespace Bitrix\Tasks\Integration\SocialNetwork\UseCase;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\Notification\Message;
use CCrmActivity;
use CCrmActivityType;
use CSite;
use CSocNetLog;
use CSocNetLogRights;
use CTaskTags;

class BaseCase
{
	protected array $siteIds = [];
	protected $dateFormat = null;

	private static $cache = [];

	protected function getSonetLogFilter(int $taskId, bool $isCrm): array
	{
		// TODO: this code was moved from classes/tasksnotifications propably needs reraftoring
		$filter = [];

		if (!$isCrm || !Loader::includeModule('crm'))
		{
			return [
				'EVENT_ID' => 'tasks',
				'SOURCE_ID' => $taskId
			];
		}

		if (array_key_exists($taskId, self::$cache))
		{
			return self::$cache[$taskId];
		}

		$res = CCrmActivity::getList(
			[],
			[
				'TYPE_ID' => CCrmActivityType::Task,
				'ASSOCIATED_ENTITY_ID' => $taskId,
				'CHECK_PERMISSIONS' => 'N'
			],
			false,
			false,
			['ID']
		);

		if ($activity = $res->fetch())
		{
			$filter = [
				'EVENT_ID' => 'crm_activity_add',
				'ENTITY_ID' => $activity
			];
		}

		self::$cache[$taskId] = $filter;

		return self::$cache[$taskId];
	}

	protected function finalizeLogCreation(int $logId, Message $message): void
	{
		if ($logId <= 0)
		{
			return;
		}

		$task = $message->getMetaData()->getTask();

		$this->updateTags($logId, $task->getId());

		$this->updateRights($message, $logId);

		CSocNetLog::sendEvent($logId, 'SONET_NEW_EVENT', $logId);
	}

	protected function updateTags(int $logId, int $taskId): void
	{
		$logFields = ['TMP_ID' => $logId];

		$logFields['TAG'] = $this->getTagNames($taskId);

		CSocNetLog::update($logId, $logFields);
	}

	protected function getTagNames(int $taskId): array
	{
		$tagNames = [];

		$tagsResult = CTaskTags::getList([], ['TASK_ID' => $taskId]);

		while ($row = $tagsResult->fetch())
		{
			$tagNames[] = $row['NAME'];
		}

		return $tagNames;
	}

	protected function updateRights(Message $message, int $logId): void
	{
		$metaData = $message->getMetaData();

		$userRepository = $metaData->getUserRepository();

		$task = $metaData->getTask();

		$taskMembers = $userRepository->getRecepients($task, $message->getSender());

		$rights = $this->recepients2Rights($taskMembers);

		if ($task->getGroupId())
		{
			$rights = array_merge($rights, ['SG' . $task->getGroupId()]);
		}

		CSocNetLogRights::add($logId, $rights);
	}

	protected function getSiteIds(): array
	{
		if (empty($this->siteIds))
		{
			$dbSite = CSite::GetList(
				'sort',
				'desc',
				['ACTIVE' => 'Y']
			);

			while ($arSite = $dbSite->Fetch())
			{
				$this->siteIds[] = $arSite['ID'];
			}
		}

		return $this->siteIds;
	}

	protected function getDateFormat()
	{
		if ($this->dateFormat === null)
		{
			$this->dateFormat = CSite::GetDateFormat('FULL', SITE_ID);
		}

		return $this->dateFormat;
	}

	protected function recepients2Rights(array $recepients): array
	{
		$rights = [];
		foreach($recepients as $user)
		{
			$rights[] = 'U' . $user->getId();
		}

		return $rights;
	}
}
