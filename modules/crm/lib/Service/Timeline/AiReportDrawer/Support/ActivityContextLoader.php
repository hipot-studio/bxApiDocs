<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Crm\Activity\BindIdentifier;
use Bitrix\Crm\Activity\Mail\CopilotThreadCollector;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Controller\Validator;
use Bitrix\Crm\Controller\Validator\Validation;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\DrawerRequest;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ActivityContextLoader
{
	public function __construct(
		private readonly ClientDataProvider $clientDataProvider,
		private readonly ResponsibleDataProvider $responsibleDataProvider,
	)
	{
	}

	public function load(DrawerRequest $request, int $currentUserId = 0): Result
	{
		$result = new Result();

		$activityResult = $this->loadAuthorizedActivity($request->activityId, $request, $currentUserId);
		if (!$activityResult->isSuccess())
		{
			return $result->addErrors($activityResult->getErrors());
		}

		$activity = $activityResult->getData()['activity'];
		$canonicalRequest = $request;

		if ($this->isEmailActivity($activity))
		{
			$canonicalActivityResult = $this->loadCanonicalEmailActivity($activity, $request, $currentUserId);
			if (!$canonicalActivityResult->isSuccess())
			{
				return $result->addErrors($canonicalActivityResult->getErrors());
			}

			$activity = $canonicalActivityResult->getData()['activity'];
			$canonicalRequest = $this->makeRequestWithActivityId($request, (int)($activity['ID'] ?? 0));
		}

		return $result->setData([
			'context' => new ActivityContext(
				request: $canonicalRequest,
				activity: $activity,
				clientData: $this->clientDataProvider->getByActivityId($canonicalRequest->activityId),
				responsibleData: $this->responsibleDataProvider->getById((int)($activity['RESPONSIBLE_ID'] ?? 0)),
				currentUserId: $currentUserId,
				originalRequest: $request,
			),
		]);
	}

	private function loadAuthorizedActivity(int $activityId, DrawerRequest $request, int $currentUserId): Result
	{
		$result = new Result();
		$itemIdentifier = ItemIdentifier::createByParams($request->ownerTypeId, $request->ownerId);
		if ($itemIdentifier === null)
		{
			return $result->addError(ErrorCode::getOwnerNotFoundError());
		}

		$binding = new BindIdentifier($itemIdentifier, $activityId);
		$validation = (new Validation())
			->validate($binding->getActivityId(), [new Validator\Activity\ActivityExists()])
			->validate($binding, [new Validator\Activity\BindingExists()])
		;

		if (!$validation->isSuccess())
		{
			return $result->addErrors($validation->getErrors());
		}

		$userPermissions = Container::getInstance()
			->getUserPermissions($currentUserId)
			->getCrmPermissions()
		;
		if (!\CCrmActivity::CheckReadPermission(
			$itemIdentifier->getEntityTypeId(),
			$itemIdentifier->getEntityId(),
			$userPermissions,
		))
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		if (!is_array($activity))
		{
			return $result->addError(new Error('Activity not found'));
		}

		return $result->setData([
			'activity' => $activity,
		]);
	}

	private function loadCanonicalEmailActivity(array $activity, DrawerRequest $request, int $currentUserId): Result
	{
		$result = new Result();

		$threadId = $this->resolveThreadId($activity);
		if ($threadId <= 0)
		{
			return $result->setData(['activity' => $activity]);
		}

		$rootActivity = CopilotThreadCollector::findEntityLocalRoot(
			$threadId,
			$request->ownerTypeId,
			$request->ownerId,
		);

		$rootActivityId = (int)($rootActivity['ID'] ?? 0);
		if ($rootActivityId <= 0 || $rootActivityId === (int)($activity['ID'] ?? 0))
		{
			return $result->setData(['activity' => $activity]);
		}

		return $this->loadAuthorizedActivity($rootActivityId, $request, $currentUserId);
	}

	private function resolveThreadId(array $activity): int
	{
		$threadId = (int)($activity['THREAD_ID'] ?? 0);
		if ($threadId > 0)
		{
			return $threadId;
		}

		$activityId = (int)($activity['ID'] ?? 0);
		if ($activityId <= 0)
		{
			return 0;
		}

		$activityRow = ActivityTable::query()
			->setSelect(['THREAD_ID'])
			->where('ID', $activityId)
			->fetch()
		;

		return (int)($activityRow['THREAD_ID'] ?? 0);
	}

	private function isEmailActivity(array $activity): bool
	{
		return ($activity['PROVIDER_ID'] ?? null) === Email::getId();
	}

	private function makeRequestWithActivityId(DrawerRequest $request, int $activityId): DrawerRequest
	{
		return new DrawerRequest(
			activityId: $activityId,
			ownerTypeId: $request->ownerTypeId,
			ownerId: $request->ownerId,
			jobId: $request->jobId,
			assessmentSettingsId: $request->assessmentSettingsId,
		);
	}
}
