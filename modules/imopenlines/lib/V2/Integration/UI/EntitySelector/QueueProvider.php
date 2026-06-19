<?php

namespace Bitrix\ImOpenLines\V2\Integration\UI\EntitySelector;

use Bitrix\ImOpenlines\Security\Permissions;
use Bitrix\ImOpenLines\V2\Queue\Queue;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;

final class QueueProvider extends BaseProvider
{
	public const ENTITY_ID = 'imopenlines-queue';

	private const QUEUE_TAB_ID = 'imopenlines-queue-tab';

	public function __construct()
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return Permissions::createWithCurrentUser()->canViewLines();
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addTab($this->getQueueTab());
		$dialog->addItems($this->getItems(null));
	}

	public function getItems(?array $ids): array
	{
		$queues = is_array($ids) ? Queue::getQueuesByIds($ids) : Queue::getQueues();
		if ($queues->isEmpty())
		{
			return [];
		}

		$items = [];
		foreach ($queues as $queue)
		{
			$items[] = new Item([
				'id' => $queue->getId(),
				'entityId' => self::ENTITY_ID,
				'tabs' => [
					self::QUEUE_TAB_ID,
				],
				'title' => $queue->getName(),
			]);
		}

		return $items;
	}

	private function getQueueTab(): Tab
	{
		return new Tab([
			'id' => self::QUEUE_TAB_ID,
			'title' => Loc::getMessage('IMOL_QUEUE_PROVIDER_TAB_TITLE'),
		]);
	}
}
