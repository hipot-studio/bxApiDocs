<?php

namespace Bitrix\Crm\Agent\Activity;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Config\Option;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Activity\Entity\EntityUncompletedActivityTable;
use Bitrix\Crm\ActivityBindingTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Crm\Activity\UncompletedActivity\UncompletedActivityRepo;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Settings\CounterSettings;
use Bitrix\Crm\Activity\LightCounter\ActCounterLightTimeRepo;
use Bitrix\Crm\Activity\UncompletedActivity\UpsertDto;
use Bitrix\Crm\Activity\LightCounter\ActCounterLightTimeTable;
use Bitrix\Crm\Activity\LightCounter\CalculateParams;
use Bitrix\Crm\Activity\LightCounter\CounterLightTime;
use Bitrix\Crm\Activity\Provider\Eventable\PingOffset;
use Bitrix\Crm\Service\Container;

class FixWrongUncompletedDueTimezoneActivityAgent extends AgentBase
{
	private const AGENT_DONE = false;

	private const AGENT_CONTINUE = true;

	private const LAST_ACTIVITY_ID_OPTION_NAME = 'WrongUncompletedDueTimezoneActivity_lastId';
	private const MAX_ACTIVITY_ID_OPTION_NAME = 'WrongUncompletedDueTimezoneActivity_maxId';
	private const LAST_ACTIVITY_LIMIT_OPTION_NAME = 'WrongUncompletedDueTimezoneActivity_limit';


	public static function doRun(): bool
	{
		$instance = new static();

		return $instance->process();
	}

	public function process(): bool
	{
		$activitiesData = $this->getActivities();
		if (empty($activitiesData))
		{
			$this->clearOptions();

			return self::AGENT_DONE;
		}

		$this->setLastActivityId(max(array_keys($activitiesData)));

		$uncompletedDataIterator = ActivityBindingTable::query()
			->registerRuntimeField(
				'UNCOMPLETED',
				new Reference('UNCOMPLETED',
					EntityUncompletedActivityTable::getEntity(),
					[
						'=ref.ENTITY_TYPE_ID' => 'this.OWNER_TYPE_ID',
						'=ref.ENTITY_ID' => 'this.OWNER_ID',
					],
					['join_type' => Join::TYPE_INNER]
				)
			)
			->whereIn('ACTIVITY_ID', array_keys($activitiesData))
			->setSelect([
				'ACTIVITY_ID',
				'UNCOMPLETED.ID',
				'UNCOMPLETED.ACTIVITY_ID',
				'UNCOMPLETED.MIN_DEADLINE',
				'UNCOMPLETED.ENTITY_TYPE_ID',
				'UNCOMPLETED.ENTITY_ID',
				'UNCOMPLETED.RESPONSIBLE_ID',
				'UNCOMPLETED.IS_INCOMING_CHANNEL',
			])
			->exec()
		;

		while ($uncompletedData = $uncompletedDataIterator->fetch())
		{
			if ($uncompletedData['ACTIVITY_ID'] != $uncompletedData['CRM_ACTIVITY_BINDING_UNCOMPLETED_ACTIVITY_ID'])
			{
				continue;
			}
			$activityData = $activitiesData[$uncompletedData['ACTIVITY_ID']] ?? [];
			$realActivityDeadline =$activityData['DEADLINE'] ?? null;
			if (!$realActivityDeadline)
			{
				continue;  // $uncompletedData bound to another activity
			}
			if ($uncompletedData['CRM_ACTIVITY_BINDING_UNCOMPLETED_IS_INCOMING_CHANNEL'] === 'Y')
			{
				continue;
			}
			$uncompletedDeadline = $uncompletedData['CRM_ACTIVITY_BINDING_UNCOMPLETED_MIN_DEADLINE'] ?? null;
			if (!$uncompletedDeadline || $uncompletedDeadline->getTimestamp() == $realActivityDeadline->getTimestamp())
			{
				continue;  // $uncompletedData data is correct
			}
			$this->fixLightTime($activityData);
			$this->fixUncompletedActivity($uncompletedData);
		}

		return self::AGENT_CONTINUE;
	}

	private function getActivities(): array
	{
		$lastId = $this->getLastActivityId();
		if (!$lastId)
		{
			return [];
		}

		$query = ActivityTable::query()
			->where('ID', '>', $lastId)
			->where('DEADLINE', '<', \CCrmDateTimeHelper::getMaxDatabaseDateObject())
			->setOrder(['ID' => 'ASC'])
			->setSelect(['ID'])
			->setLimit($this->getLimit())
		;

		$maxActivityId = $this->getMaxSuitableActivityId();
		if ($maxActivityId > 0)
		{
			$query->where('ID', '<', $maxActivityId);
		}
		$activityIds = array_column($query->fetchAll(), 'ID');

		if (empty($activityIds))
		{
			return [];
		}

		$iterator = ActivityTable::query()
			->whereIn('ID', $activityIds)
			->setSelect(['ID', 'DEADLINE', 'NOTIFY_TYPE', 'NOTIFY_VALUE'])
			->exec()
		;

		$result = [];
		while ($activity = $iterator->fetch())
		{
			$result[$activity['ID']] = $activity;
		}

		return $result;
	}

	private function fixUncompletedActivity($uncompletedData): void
	{
		$itemIdentifier = ItemIdentifier::createByParams(
			$uncompletedData['CRM_ACTIVITY_BINDING_UNCOMPLETED_ENTITY_TYPE_ID'],
			$uncompletedData['CRM_ACTIVITY_BINDING_UNCOMPLETED_ENTITY_ID'],
		);
		if (!$itemIdentifier)
		{
			return;
		}

		$responsibleId = $uncompletedData['CRM_ACTIVITY_BINDING_UNCOMPLETED_RESPONSIBLE_ID'];

		$uncompletedActivityRepo = new UncompletedActivityRepo($itemIdentifier, $responsibleId);
		$uncompletedActivity = $this->getRealUncompletedActivity($itemIdentifier, $responsibleId);

		if ($uncompletedActivity)
		{
			$activityId = (int)$uncompletedActivity['ID'];
			$deadline = $uncompletedActivity['DEADLINE'] ?? \CCrmDateTimeHelper::GetMaxDatabaseDate(false);
			$isIncomingChannel = false;
			$incomingChannelActivityId = $uncompletedActivityRepo->getUncompletedIncomingActivityId();
			$hasAnyIncomingChannel = !!$incomingChannelActivityId;
			if (\CCrmDateTimeHelper::IsMaxDatabaseDate($deadline))
			{
				if ($incomingChannelActivityId)
				{
					$activityId = $incomingChannelActivityId;
					$isIncomingChannel = true;
				}
				$deadlineDateTime = \CCrmDateTimeHelper::getMaxDatabaseDateObject();
			}
			else
			{
				$deadlineDateTime = DateTime::createFromUserTime($deadline);
			}

			$responsibleToCalcLightTime = null;
			if (CounterSettings::getInstance()->useActivityResponsible())
			{
				$responsibleToCalcLightTime = $responsibleId > 0 ? $responsibleId : null;
			}

			$minLightTime = (new ActCounterLightTimeRepo())->minLightTimeByItemIdentifier(
				$itemIdentifier,
				$responsibleToCalcLightTime
			);

			$uncompletedActivityRepo->upsert(
				new UpsertDto(
					$activityId,
					$deadlineDateTime,
					$isIncomingChannel,
					$hasAnyIncomingChannel,
					$minLightTime,
					$itemIdentifier,
					$responsibleId
				)
			);
			$this->log('changed uncompleted deadline for activity ' . $activityId, [
				'oldDeadline' => $uncompletedData['CRM_ACTIVITY_BINDING_UNCOMPLETED_MIN_DEADLINE']?->format('Y-m-d H:i:s'),
				'newDeadline' => $deadlineDateTime->format('Y-m-d H:i:s'),
				'responsibleId' => $responsibleId,
				'entity' => $itemIdentifier->jsonSerialize(),
			]);
		}
		else
		{
			// there are no uncompleted activities
			$existedRecordId = $uncompletedActivityRepo->getExistedRecordId();
			if ($existedRecordId)
			{
				EntityUncompletedActivityTable::delete($uncompletedData['CRM_ACTIVITY_BINDING_UNCOMPLETED_ID']);
			}
		}
	}

	private function fixLightTime(array $activityData): void
	{
		$activityId = $activityData['ID'] ?? 0;
		static $processedActivities = [];
		if (!isset($processedActivities[$activityId]))
		{
			$processedActivities[$activityId] = true;

			$currentLightTime = ActCounterLightTimeTable::query()
				->setSelect(['ACTIVITY_ID', 'LIGHT_COUNTER_AT'])
				->where('ACTIVITY_ID', $activityId)
				->setLimit(1)
				->fetch()
			;

			if (!$currentLightTime)
			{
				return;
			}
			$pingOffsets = PingOffset::getInstance()->getOffsetsByActivityId($activityId);

			$realLightTime = (new CounterLightTime())->calculate(CalculateParams::createFromArrays($activityData, $pingOffsets));

			if ($currentLightTime['LIGHT_COUNTER_AT'] && $realLightTime->getTimestamp() != $currentLightTime['LIGHT_COUNTER_AT']->getTimestamp())
			{
				ActCounterLightTimeTable::update($currentLightTime['ACTIVITY_ID'], ['LIGHT_COUNTER_AT' => $realLightTime]);
				$this->log('changed LightTime for activity ' . $activityId . ' from ' . $currentLightTime['LIGHT_COUNTER_AT']->format('Y-m-d H:i:s') . ' to ' . $realLightTime->format('Y-m-d H:i:s'));
			}
		}
	}

	private function getRealUncompletedActivity(ItemIdentifier $itemIdentifier, int $userId): ?array
	{
		$filter = [
			'!=DEADLINE' => null,
			'BINDINGS' => [
				[
					'OWNER_TYPE_ID' => $itemIdentifier->getEntityTypeId(),
					'OWNER_ID' => $itemIdentifier->getEntityId(),
				],
			],
			'CHECK_PERMISSIONS' => 'N',
			'COMPLETED' => 'N',
		];
		if ($userId)
		{
			$filter['RESPONSIBLE_ID'] = $userId;
		}

		$firstUncompletedActivityWithExplicitDeadline = \CCrmActivity::GetList(
			[
				'DEADLINE' => 'ASC',
			],
			$filter,
			false,
			['nTopCount' => 1],
			[
				'ID',
				'DEADLINE',
			]
		)->Fetch();

		if ($firstUncompletedActivityWithExplicitDeadline)
		{
			return $firstUncompletedActivityWithExplicitDeadline;
		}

		return null;
	}

	private function getMinCreatedDate(): DateTime
	{
		$date = new DateTime();
		$date->setDate(2026, 1, 27);

		return $date;
	}

	private function getMaxCreatedDate(): DateTime
	{
		$maxTimestamp = Option::get('crm', 'crm_26.200.0_installed_ts', mktime(0, 0,0, 3, 20, 2026));

		return DateTime::createFromTimestamp($maxTimestamp);
	}

	private function getLimit(): int
	{
		return Option::get('crm_agent', self::LAST_ACTIVITY_LIMIT_OPTION_NAME, 100);
	}

	private function getLastActivityId(): int
	{
		$lastId = Option::get('crm_agent', self::LAST_ACTIVITY_ID_OPTION_NAME, -1);
		if ($lastId < 0)
		{
			$lastId = $this->getMinSuitableActivityId();
		}

		return $lastId;
	}

	private function getMinSuitableActivityId(): int
	{
		$result = ActivityTable::query()
			->setSelect(['ID'])
			->setLimit(1)
			->where('CREATED', '>', $this->getMinCreatedDate())
			->fetch()
		;

		return $result ? ($result['ID'] - 1) : 0;
	}


	private function getMaxSuitableActivityId(): int
	{
		$maxSuitableActivityId = Option::get('crm_agent', self::MAX_ACTIVITY_ID_OPTION_NAME, -1);
		if ($maxSuitableActivityId < 0)
		{
			$result = ActivityTable::query()
				->setSelect(['ID'])
				->setLimit(1)
				->where('CREATED', '>', $this->getMaxCreatedDate())
				->fetch()
			;

			$maxSuitableActivityId = $result ? $result['ID'] : 0;

			Option::set('crm_agent', self::MAX_ACTIVITY_ID_OPTION_NAME, $maxSuitableActivityId);
		}

		return $maxSuitableActivityId;
	}

	private function setLastActivityId(int $id): void
	{
		Option::set('crm_agent', self::LAST_ACTIVITY_ID_OPTION_NAME, $id);
	}

	private function clearOptions(): void
	{
		Option::delete('crm_agent', ['name' => self::LAST_ACTIVITY_ID_OPTION_NAME]);
		Option::delete('crm_agent', ['name' => self::LAST_ACTIVITY_LIMIT_OPTION_NAME]);
		Option::delete('crm_agent', ['name' => self::MAX_ACTIVITY_ID_OPTION_NAME]);
		Option::delete('crm', ['name' => 'crm_26.200.0_installed_ts']);
	}

	private function log(string $message, array $context = []): void
	{
		Container::getInstance()->getLogger('Agent')->info($message, $context);
	}
}
