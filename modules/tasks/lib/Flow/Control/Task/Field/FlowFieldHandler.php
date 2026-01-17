<?php

namespace Bitrix\Tasks\Flow\Control\Task\Field;

use Bitrix\Tasks\Flow\Control\Task\Exception\FlowTaskException;
use Bitrix\Tasks\Flow\Distribution\FlowDistributionServicesFactory;
use Bitrix\Tasks\Flow\FlowFeature;
use Bitrix\Tasks\Flow\Provider\Exception\FlowNotFoundException;
use Bitrix\Tasks\Flow\Provider\FlowProvider;
use Bitrix\Tasks\Flow\Responsible\Distributor;
use Bitrix\Tasks\Flow\Task\Trait\TaskFlowTrait;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\Type\DateTime;
use Bitrix\Tasks\Util\User;

class FlowFieldHandler
{
	use TaskFlowTrait;

	protected FlowProvider $provider;
	protected Distributor $distributor;

	protected int $flowId;
	protected int $userId;

	public function __construct(int $flowId, int $userId = 0)
	{
		$this->flowId = $flowId;
		$this->userId = $userId;

		$this->init();
	}

	/**
	 * @throws FlowNotFoundException
	 * @throws FlowTaskException
	 */
	public function modify(array &$fields, array &$skipTimeZoneFields, array $taskData = []): array
	{
		$flow = $this->provider->getFlow($this->flowId, ['*', 'OPTIONS']);

		if (!FlowFeature::isFeatureEnabled())
		{
			throw new FlowTaskException('You cannot run a task without flow feature');
		}

		if (!$flow->isActive())
		{
			if (isset($fields['FLOW_ID']))
			{
				unset($fields['FLOW_ID']);
			}

			return $fields;
		}

		$responsible = $this->distributor->generateResponsible($flow, $fields, $taskData);
		$fields['RESPONSIBLE_ID'] = $responsible->getId();

		$isTaskAddedToFlow = $this->isTaskAddedToFlow($fields, $taskData);

		if (empty($taskData) || $isTaskAddedToFlow)
		{
			$deadline = $this->getDeadlineMatchWorkTime(
				$flow->getPlannedCompletionTime(),
				$fields['RESPONSIBLE_ID'],
				$flow->getMatchSchedule(),
				$flow->getMatchWorkTime(),
			);

			$fields['DEADLINE'] = UI::formatDateTime($deadline->convertToLocalTime()->getTimestamp());
			if (!in_array('DEADLINE', $skipTimeZoneFields, true))
			{
				$skipTimeZoneFields[] = 'DEADLINE';
			}

			$fields['MATCH_WORK_TIME'] = $flow->getMatchWorkTime();
			$fields['GROUP_ID'] = $flow->getGroupId();
			$fields['TASK_CONTROL'] = $flow->getTaskControl();
			$fields['ALLOW_CHANGE_DEADLINE'] = $flow->canResponsibleChangeDeadline();
		}

		return $fields;
	}

	public function getModifiedFields(): array
	{
		if ($this->flowId <= 0)
		{
			return [];
		}

		try
		{
			$flow = $this->provider->getFlow($this->flowId, ['DISTRIBUTION_TYPE']);
		}
		catch (FlowNotFoundException)
		{
			return [];
		}

		$distributionType = $flow->getDistributionType();

		return (new FlowDistributionServicesFactory($distributionType))
			->getFieldsProvider()
			->getModifiedFields();
	}

	private function getDeadlineMatchWorkTime(
		int $offsetInSeconds,
		int $responsibleId,
		bool $matchSchedule = false,
		bool $matchWorkTime = false,
	): DateTime
	{
		$deadline = $this->getResponsibleTZDeadline(
			$offsetInSeconds,
			$responsibleId,
			$matchSchedule,
			$matchWorkTime
		);

		$responsibleTimeOffset = User::getTimeZoneOffset($responsibleId);
		$toServerTimeOffset = -$responsibleTimeOffset;

		return $deadline->add("{$toServerTimeOffset} seconds");
	}

	private function getResponsibleTZDeadline(
		int $offsetInSeconds,
		int $responsibleId,
		bool $matchSchedule = false,
		bool $matchWorkTime = false,
	): DateTime
	{
		$calendar = \Bitrix\Tasks\Integration\Calendar\Calendar::createFromPortalSchedule();

		return $calendar->getClosestDate(
			DateTime::createFromTimestamp(User::getTime($responsibleId)),
			$offsetInSeconds,
			$matchSchedule,
			$matchWorkTime,
		);
	}

	protected function init(): void
	{
		$this->provider = new FlowProvider();
		$this->distributor = new Distributor();
	}
}
