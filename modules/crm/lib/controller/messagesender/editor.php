<?php

namespace Bitrix\Crm\Controller\MessageSender;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;
use Bitrix\MessageService\Public\UI\MessageEditor\Scene\NullScene;

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
		$filters[] = new class extends ActionFilter\Base {
			public function onBeforeAction(Event $event): ?EventResult
			{
				if (Loader::includeModule('messageservice'))
				{
					return null;
				}

				$this->addError(ErrorCode::getModuleNotInstalledError('messageservice'));

				return new EventResult(EventResult::ERROR, null, null, $this);
			}
		};

		return $filters;
	}

	// todo move to crm.activity.sms ?
	public function loadAction(
		string $sceneId,
		?array $customData = null,
	): ?array
	{
		$factory = \Bitrix\Crm\MessageSender\UI\Factory::getInstance();

		$scene = empty($sceneId) ? new NullScene() : $factory->getScene($sceneId);
		if (!$scene)
		{
			$this->addError(new Error('Scene not found', 'SCENE_NOT_FOUND'));

			return null;
		}

		$editor = \Bitrix\Crm\MessageSender\UI\Factory::getInstance()->createEditor(
			$scene,
			new Context(customData: $customData),
		);

		return [
			'editor' => $editor,
		];
	}
}
