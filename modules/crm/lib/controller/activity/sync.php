<?php

namespace Bitrix\Crm\Controller\Activity;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\V2\Internal\Service\Activity\Sync\SyncCallListService;
use Bitrix\Crm\V2\Internal\Service\Activity\Sync\SyncCreationService;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\ActionFilter\Scope;

class Sync extends Base
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new Scope(Scope::NOT_REST),
		];
	}

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();
		$configureActions['create'] = [
			'+prefilters' => [
				new HttpMethod([HttpMethod::METHOD_POST]),
			],
		];

		return $configureActions;
	}

	public function createAction(int $ownerTypeId, int $ownerId): ?array
	{
		$result = (new SyncCreationService())->create($ownerTypeId, $ownerId, (int)$this->getCurrentUser()?->getId());
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		$data = $result->getData();

		return [
			'chatId' => (int)$data['chatId'],
			'dialogId' => (string)$data['dialogId'],
			'activityId' => (int)$data['activityId'],
		];
	}

	public function listCallsAction(int $activityId): ?array
	{
		$result = $this->createCallListService()->list((int)$this->getCurrentUser()?->getId(), $activityId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	protected function createCallListService(): SyncCallListService
	{
		return new SyncCallListService();
	}
}
