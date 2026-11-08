<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Event;

use Bitrix\Main\EventResult;
use Bitrix\Tasks\Onboarding\Internal\Factory\CommandModelFactory;
use Bitrix\Tasks\Onboarding\Internal\Type;
use Bitrix\Tasks\Onboarding\OnboardingFeature;
use Bitrix\Tasks\Onboarding\Transfer\CommandModelCollection;
use Bitrix\Tasks\Provider\TaskList;
use Bitrix\Tasks\Provider\Query\TaskQuery;

final class OnFirstUserAuthorizeListener extends AbstractEventListener
{
	private const TASKS_LIMIT = 100;

	public function __construct(private readonly TaskList $taskList = new TaskList())
	{
		parent::__construct();
	}

	public function onUserInitialize(array $data): EventResult
	{
		$eventResult = new EventResult(EventResult::SUCCESS);

		if (defined('BX_SECURITY_SESSION_VIRTUAL') && BX_SECURITY_SESSION_VIRTUAL === true)
		{
			return $eventResult;
		}

		$fields = $data['user_fields'] ?? [];
		$userId = (int)($fields['ID'] ?? 0);

		$isUpdate = $data['update'] ?? false;
		if (!$isUpdate)
		{
			return $eventResult;
		}

		if (!empty($fields['LAST_LOGIN']))
		{
			return $eventResult;
		}

		if ($userId <= 0)
		{
			return $eventResult;
		}

		$this->deleteByUserJob([Type::ResponsibleInvitationNotAcceptedOneDay], $userId);
		if (!OnboardingFeature::isAvailable())
		{
			return $eventResult;
		}

		$taskIds = $this->getUserTaskIds($userId);

		$commandModels = new CommandModelCollection();
		foreach ($taskIds as $taskId)
		{
			$acceptedModel = CommandModelFactory::create(Type::ResponsibleInvitationAccepted, $taskId, $userId);
			$notViewedModel = CommandModelFactory::create(Type::InvitedResponsibleNotViewTaskTwoDays, $taskId, $userId);

			$commandModels->add($acceptedModel);
			$commandModels->add($notViewedModel);
		}

		$this->saveCommandModels($commandModels);

		return $eventResult;
	}

	private function getUserTaskIds(int $userId): array
	{
		$query = (new TaskQuery($userId))
			->setSelect(['ID'])
			->setWhere(['RESPONSIBLE_ID' => $userId])
			->setLimit(self::TASKS_LIMIT);

		$tasks = $this->taskList->getList($query);

		return array_map('intval', array_column($tasks, 'ID'));
	}
}
