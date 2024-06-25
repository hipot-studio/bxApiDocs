<?php

namespace Bitrix\Tasks\Flow\Provider;

use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Tasks\Flow\FlowCollection;
use Bitrix\Tasks\Flow\Internal\Entity\Role;
use Bitrix\Tasks\Flow\Internal\FlowMemberTable;
use Bitrix\Tasks\Flow\Provider\Exception\ProviderException;

class MembersProvider
{
	private FlowProvider $flowProvider;

	public function __construct()
	{
		$this->init();
	}

	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function getTeamCount(FlowCollection $flows): array
	{
		return $this->getManuallyTeamCount($flows) + $this->getQueueTeamCount($flows);
	}

	/**
	 * @throws ProviderException
	 * @throws LoaderException
	 */
	public function getAssignees(int $flowId, ?int $offset = null, ?int $limit = null): array
	{
		$flow = $this->flowProvider->getFlow($flowId);

		if ($flow->isManually())
		{
			return $this->getProjectTeam($flow->getGroupId(), $offset, $limit);
		}

		if ($flow->isQueue())
		{
			return $this->getQueueTeam($flow->getId(), $offset, $limit);
		}

		return [];
	}

	/**
	 * @throws ProviderException
	 */
	public function getTaskCreators(int $flowId, ?int $offset = null, ?int $limit = null): array
	{
		try
		{
			$query = FlowMemberTable::query()
				->setSelect(['ID', 'ACCESS_CODE'])
				->where('FLOW_ID', $flowId)
				->where('ROLE', Role::TASK_CREATOR->value)
				->setOffset($offset)
				->setLimit($limit);

			return $query->exec()->fetchCollection()->getAccessCodeList();
		}
		catch (SystemException $e)
		{
			throw new ProviderException($e->getMessage());
		}
	}

	/**
	 * @throws ProviderException
	 * @throws LoaderException
	 */
	private function getProjectTeam(int $projectId, ?int $offset = null, ?int $limit = null): array
	{
		try
		{
			if (!Loader::includeModule('socialnetwork'))
			{
				return [];
			}

			$query = UserToGroupTable::query()
				->setSelect(['ID', 'USER_ID'])
				->where('GROUP_ID', $projectId)
				->where('USER.ACTIVE', 'Y')
				->setOffset($offset)
				->setLimit($limit);

			return $query->exec()->fetchCollection()->getUserIdList();
		}
		catch (SystemException $e)
		{
			throw new ProviderException($e->getMessage());
		}
	}

	/**
	 * @throws ProviderException
	 */
	private function getQueueTeam(int $flowId, ?int $offset = null, ?int $limit = null): array
	{
		try
		{
			$query = FlowMemberTable::query()
				->setSelect(['ID', 'ACCESS_CODE'])
				->where('FLOW_ID', $flowId)
				->where('ROLE', Role::QUEUE_ASSIGNEE->value)
				->setOffset($offset)
				->setLimit($limit);

			$accessCodes = $query->exec()->fetchCollection()->getAccessCodeList();
		}
		catch (SystemException $e)
		{
			throw new ProviderException($e->getMessage());
		}

		$userIds = [];
		foreach ($accessCodes as $accessCode)
		{
			$access = new AccessCode($accessCode);
			$userIds[] = $access->getEntityId();
		}

		return $userIds;
	}

	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function getManuallyTeamCount(FlowCollection $flows): array
	{
		$flows = $flows->getManuallyFlows();

		if ($flows->isEmpty())
		{
			return [];
		}

		$counts = UserToGroupTable::query()
			->setSelect(['GROUP_ID', Query::expr('TOTAL_COUNT')->countDistinct('USER_ID')])
			->whereIn('GROUP_ID', array_unique($flows->getGroupIdList()))
			->where('USER.ACTIVE', 'Y')
			->setGroup(['GROUP_ID'])
			->exec()
			->fetchAll();

		$flowCounts = [];

		foreach ($counts as $count)
		{
			$flowCounts[(int)$count['GROUP_ID']] = (int)$count['TOTAL_COUNT'];
		}

		$teams = [];

		foreach ($flows as $flow)
		{
			if (!isset($flowCounts[$flow->getGroupId()]))
			{
				continue;
			}

			$teams[$flow->getId()] = (int)$flowCounts[$flow->getGroupId()];
		}

		return $teams;
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function getQueueTeamCount(FlowCollection $flows): array
	{
		$flows = $flows->getQueueFlows();

		if ($flows->isEmpty())
		{
			return [];
		}

		$counts = FlowMemberTable::query()
			->setSelect(['FLOW_ID',  Query::expr('TOTAL_COUNT')->countDistinct('ID')])
			->whereIn('FLOW_ID', $flows->getIdList())
			->where('ROLE', Role::QUEUE_ASSIGNEE->value)
			->where('USER.ACTIVE', 'Y')
			->addGroup('FLOW_ID')
			->exec()
			->fetchAll();

		$teams = [];
		foreach ($counts as $count)
		{
			$teams[(int)$count['FLOW_ID']] = (int)$count['TOTAL_COUNT'];
		}

		return $teams;
	}

	private function init(): void
	{
		$this->flowProvider = new FlowProvider();
	}
}