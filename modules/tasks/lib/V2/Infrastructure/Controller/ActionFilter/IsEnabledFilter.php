<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Tasks\V2\Infrastructure;
use Bitrix\Tasks\V2\FormV2Feature;

class IsEnabledFilter extends Base
{
	protected const ENABLED_ACTIONS = [
		Infrastructure\Controller\Task::class => ['add', 'get', 'list'],
		Infrastructure\Rest\Controller\Task::class => ['get', 'list'],
		Infrastructure\Controller\CheckList::class => ['save'],
		Infrastructure\Controller\Group::class => ['get'],
		Infrastructure\Controller\Group\Url::class => ['get'],
	];

	public function onBeforeAction(Event $event): ?EventResult
	{
		/** @var Controller $controller */
		$controller = $event->getParameter('controller');
		/** @var Action $action */
		$action = $event->getParameter('action');

		if (FormV2Feature::isOn())
		{
			return null;
		}

		if (
			isset(static::ENABLED_ACTIONS[$controller::class])
			&& in_array($action->getName(), static::ENABLED_ACTIONS[$controller::class], true)
		)
		{
			return null;
		}

		$this->addError(new Error('Action is disabled'));

		return new EventResult(type: EventResult::ERROR, handler: $this);
	}
}
