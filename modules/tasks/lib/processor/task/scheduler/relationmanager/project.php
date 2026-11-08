<?
/**
 * This class could be changed (removed, renamed, relocated) in any time, so do not use it in public code
 *
 * @internal
 * @access private
 */

namespace Bitrix\Tasks\Processor\Task\Scheduler\RelationManager;

use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Integration\Report\Internals\TaskTable;
use Bitrix\Tasks\Internals\RunTime;
use Bitrix\Tasks\Internals\Task\ProjectDependenceTable;
use Bitrix\Tasks\Processor\Task\Scheduler\Result\Impact;
use Bitrix\Tasks\Processor\Task\Scheduler\RelationManager\Project\Relation;
use Bitrix\Tasks\Processor\Task\Result;

final class Project extends \Bitrix\Tasks\Processor\Task\Scheduler\RelationManager
{
	public static function getCode()
	{
		return 'P';
	}

	/**
	 * @param \Bitrix\Tasks\Processor\Task\Scheduler\Result\Impact $rootImpact
	 * @param Result $result
	 * @param mixed[] $settings
	 * @throws \Bitrix\Main\NotImplementedException
	 * @return void
	 */
	public function processTask($rootImpact, $result, array $settings = array())
	{
		if(!$rootImpact)
		{
			return;
		}

		$rootImpact->setAsHead();

		$id = $rootImpact->getId();
		if($id <= 0)
		{
			return;
		}

		//_print_r('============== Process project on '.$id);

		// get task sub-tree
		// do not attach sql rights checking, because it is quite heavy still
		// instead, use php checking below

		// todo: getSubTree() should became deprecated
		$res = ProjectDependenceTable::getSubTree($id, Runtime::apply(array(
			'runtime' => array(
				new ReferenceField(
					'PARENT',
					ProjectDependenceTable::getEntity(),
					array(
						'=this.TASK_ID' => 'ref.TASK_ID',
						'=ref.DIRECT' => array('?', '1'),
					)
				)
			),
			'select' => array(
				// relation data
				'TASK_ID',
				'EFFECTIVE_PARENT_ID' => 'PARENT.DEPENDS_ON_ID',
				'RELATION_TYPE' => 'PARENT.TYPE',
				'RELATION_DIRECT' => 'PARENT.DIRECT',

				// task data
				// todo: use Impact::getBaseMixin() here
				'TITLE' => 'TASK.TITLE', // tmp
				'START_DATE_PLAN' => 'TASK.START_DATE_PLAN',
				'END_DATE_PLAN' => 'TASK.END_DATE_PLAN',
				'CREATED_DATE' => 'TASK.CREATED_DATE',
				'MATCH_WORK_TIME' => 'TASK.MATCH_WORK_TIME',
				'ALLOW_CHANGE_DEADLINE' => 'TASK.ALLOW_CHANGE_DEADLINE',
				'DURATION_TYPE' => 'TASK.DURATION_TYPE',
				'DURATION_PLAN' => 'TASK.DURATION_PLAN',
				// task fields for php rights checking
				'RESPONSIBLE_ID' => 'TASK.RESPONSIBLE_ID',
				'CREATED_BY' => 'TASK.CREATED_BY',
				'GROUP_ID' => 'TASK.GROUP_ID',
				'STATUS' => 'TASK.STATUS',
			),
		), array(
			RunTime\Task::getTask(array('REF_FIELD' => 'TASK_ID', 'JOIN_TYPE' => 'inner')),
		)), array('INCLUDE_SELF' => true));

		$tasks = array();
		$relationsData = array();
		$children = array();
		while($item = $res->fetch())
		{
			$taskId = $item['TASK_ID'];
			$parentId = (int)$item['EFFECTIVE_PARENT_ID'];
			$relationType = (int)$item['RELATION_TYPE'];
			$isDirect = (int)$item['RELATION_DIRECT'] === 1;

			unset($item['TASK_ID']);
			unset($item['RELATION_TYPE']);
			unset($item['RELATION_DIRECT']);
			$item['ID'] = $taskId;

			$tasks[$taskId] = $item;
			if ($taskId != $id && $isDirect && $parentId > 0 && $parentId !== (int)$taskId)
			{
				$relationsData[$taskId][$parentId] = array(
					'TYPE' => $relationType,
				);
				$children[$parentId][$taskId] = true;
			}

			//_print_r('----------------- subtask is: '.$item['PARENT_TASK_ID'].' -> '.$item['TASK_ID'].' '.$item['TITLE']);
		}

		// here we need to skip task which we can not move (and subtrees of such tasks)
		$tasks = $this->checkCoherence($id, $tasks, $children, $result);

		$relations = array();
		$relationsFlat = array();
		if($result->isSuccess())
		{
			$scheduler = $this->getScheduler();
			foreach($tasks as $task)
			{
				// todo: Impact class is TEMPORAL, it should be replaced with (or at least inherited from) \Bitrix\Tasks\Item\Task when ready
				$taskImpact = new Impact($task, $this->getUserId());
				$scheduler->pushQueue($taskImpact->getId(), $scheduler->getRelationProcessor('S'));
				$scheduler->addImpact($taskImpact);
			}

			$parentImpacts = $this->getParentImpacts($relationsData, $scheduler);
			foreach ($tasks as $task)
			{
				$taskId = (int)$task['ID'];
				foreach ($relationsData[$taskId] ?? array() as $parentTaskId => $relationData)
				{
					$parentTask = $scheduler->getImpactById($parentTaskId) ?? ($parentImpacts[$parentTaskId] ?? null);
					if (!$parentTask)
					{
						continue;
					}

					$relation = new Relation($relationData);
					$relation->setTask($scheduler->getImpactById($taskId));
					$relation->setParentTask($parentTask); // update lag here will happen

					$relationsFlat[] = $relation;
					$relations[$relation->getParentTaskId()][$relation->getTaskId()] = $relation;
				}
			}

			$this->updateRelatedTasks($id, $relations, array_fill_keys(array_keys($tasks), true));
			$this->dumpRelations($relationsFlat);
		}
	}

	public static function isTaskBelong($id, $data = array())
	{
		return $id && ProjectDependenceTable::checkItemLinked($id);
	}

	/**
	 * @param $id
	 * @param Relation[][] $relations
	 */
	private function updateRelatedTasks($id, array $relations, array $accessibleTasks)
	{
		$queue = array($id);
		$processedTasks = array($id => true);
		$skippedTasks = array();
		$incomingRelations = array();
		foreach ($relations as $outgoingRelations)
		{
			foreach ($outgoingRelations as $relation)
			{
				$incomingRelations[$relation->getTaskId()][] = $relation;
			}
		}

		$limit = 0;
		while (count($queue) && $limit < 10000)
		{
			$limit++;
			$parentTaskId = array_shift($queue);
			foreach ($relations[$parentTaskId] ?? array() as $relation)
			{
				$toTaskId = $relation->getTaskId();
				if (isset($processedTasks[$toTaskId]) || isset($skippedTasks[$toTaskId]))
				{
					continue;
				}
				if (!$this->areIncomingTasksSettled(
					$incomingRelations[$toTaskId],
					$processedTasks,
					$skippedTasks,
					$accessibleTasks,
				))
				{
					continue;
				}

				/** @var Impact $toTask */
				$toTask = $relation->getTask();
				$endDate = $toTask->getEndDatePlan();
				if(!$endDate) // somehow there are no end date, get out of here asap
				{
					$skippedTasks[$toTaskId] = true;
					$queue[] = $toTaskId;

					continue;
				}

				$startDate = $this->getLatestMinDate($incomingRelations[$toTaskId]);
				if (!$startDate)
				{
					$skippedTasks[$toTaskId] = true;
					$queue[] = $toTaskId;

					continue;
				}
				$startDate = clone $startDate;

				$duration = $toTask->calculateDuration();

				$endDate = clone $startDate;
				$endDate->addSecond($duration);

				// set task dates here
				$toTask->setStartDatePlanUserTimeGmt($startDate->toStringGmt());
				$toTask->setEndDatePlanUserTimeGmt($endDate->toStringGmt());

				$toTask->correctWorkTime($startDate, $endDate, $duration);

				foreach ($incomingRelations[$toTaskId] as $incomingRelation)
				{
					$incomingRelation->updateLag();
					$incomingRelation->setProcessed();
				}

				$processedTasks[$toTaskId] = true;
				$queue[] = $toTaskId;
			}
		}
	}

	private function areIncomingTasksSettled(
		array $relations,
		array $processedTasks,
		array $skippedTasks,
		array $accessibleTasks
	)
	{
		foreach ($relations as $relation)
		{
			$parentTaskId = $relation->getParentTaskId();
			if (
				isset($accessibleTasks[$parentTaskId])
				&& !isset($processedTasks[$parentTaskId])
				&& !isset($skippedTasks[$parentTaskId])
			)
			{
				return false;
			}
		}

		return true;
	}

	private function getLatestMinDate(array $relations)
	{
		$latestDate = null;
		foreach ($relations as $relation)
		{
			$minDate = $relation->getMinDate();
			if ($minDate && (!$latestDate || $minDate->getTimestamp() > $latestDate->getTimestamp()))
			{
				$latestDate = $minDate;
			}
		}

		return $latestDate;
	}

	private function getParentImpacts(array $relationsData, $scheduler)
	{
		$parentIds = array();
		foreach ($relationsData as $taskRelations)
		{
			foreach ($taskRelations as $parentTaskId => $relationData)
			{
				if (!$scheduler->hasImpact($parentTaskId))
				{
					$parentIds[$parentTaskId] = true;
				}
			}
		}

		if (empty($parentIds))
		{
			return array();
		}

		$parentImpacts = array();
		$select = Impact::getBaseMixin()['select'];
		$select[] = 'ID';
		$res = TaskTable::getList(array(
			'filter' => array('@ID' => array_keys($parentIds)),
			'select' => $select,
		));
		while ($task = $res->fetch())
		{
			$parentImpacts[$task['ID']] = new Impact($task, $this->getUserId());
		}

		return $parentImpacts;
	}

	/**
	 * @param $id
	 * @param $tasks
	 * @param Result $result
	 * @return array
	 */
	private function checkCoherence($id, $tasks, $children, $result)
	{
		$accessible = array();
		$queue = array($id);
		$limit = 0;
		$discovered = array($id => true);

		while(count($queue) && $limit < 10000)
		{
			$limit++;
			$nextId = array_shift($queue);

			if(is_array($children[$nextId] ?? null))
			{
				foreach($children[$nextId] as $nextSubId => $unused)
				{
					if (isset($discovered[$nextSubId]))
					{
						continue;
					}
					$discovered[$nextSubId] = true;

					$nextSub = $tasks[$nextSubId] ?? null;
					if (!$nextSub)
					{
						continue;
					}
					if($this->canModifyTask($nextSub))
					{
						$accessible[$nextSubId] = $nextSub;
						$queue[] = $nextSubId;
					}
				}
			}
		}

		if($limit == 10000)
		{
			$result->addError('ILLEGAL_STRUCTURE.DEPTH', 'Insane project tree depth faced');
		}

		return $accessible;
	}

	private function canModifyTask($task)
	{
		// no deal with tasks with no created date, skip the subtree
		if((string) $task['CREATED_DATE'] == '')
		{
			return false;
		}

		$instance = \CTaskItem::getInstance($task['ID'], $this->getUserId()); // todo: user should come from outside
		return $instance->checkAccess(ActionDictionary::ACTION_TASK_DEADLINE);
	}

	public function dumpRelations($relations)
	{
		if(!$this->isDebugEnabled())
		{
			return;
		}

		/** @var Relation $relation */
		foreach($relations as $relation)
		{
			_print_r($relation->dump());
		}
	}
}
