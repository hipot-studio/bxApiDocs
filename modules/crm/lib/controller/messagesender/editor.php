<?php

namespace Bitrix\Crm\Controller\MessageSender;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\MessageSender\UI\Editor\Context;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class Editor extends Base
{
	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new ActionFilter\Scope(ActionFilter\Scope::AJAX);
		$filters[] = new ActionFilter\ContentType([ ActionFilter\ContentType::JSON ]);
		$filters[] = new class extends ActionFilter\Base {
			public function onBeforeAction(Event $event): ?EventResult
			{
				if (Container::getInstance()->getUserPermissions()->messageSender()->canSendFromSomeItemsInCrmOrAutomatedSolutions())
				{
					return null;
				}

				$this->addError(ErrorCode::getAccessDeniedError());

				return new EventResult(EventResult::ERROR, null, null, $this);
			}
		};

		return $filters;
	}

	// todo move to crm.activity.sms ?
	public function loadAction(
		string $sceneId,
		?int $entityTypeId = null,
		?int $entityId = null,
		?int $categoryId = null,
	): ?array
	{
		$factory = \Bitrix\Crm\MessageSender\UI\Factory::getInstance();

		$scene = $factory->getScene($sceneId);
		if (!$scene)
		{
			$this->addError(new Error('Scene not found', 'SCENE_NOT_FOUND'));

			return null;
		}

		$editor = \Bitrix\Crm\MessageSender\UI\Factory::getInstance()->createEditor(
			$scene,
			new Context($entityTypeId, $entityId, $categoryId),
		);

		return [
			'editor' => $editor,
		];
	}
}
