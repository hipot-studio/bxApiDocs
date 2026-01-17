<?php

namespace Bitrix\Mail\Access;

use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Mail\Access\Model\UserModel;
use Bitrix\Mail\Access\Rule\Factory\RuleFactory;
use Bitrix\Mail\Access\Rule\MailBaseRule;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Main\Access\Event\EventDictionary;
use Bitrix\Main\Access\Exception\UnknownActionException;
use Bitrix\Main\Access\User\AccessibleUser;

abstract class BaseMailAccessController extends BaseAccessController
{
	public function __construct(int $userId)
	{
		parent::__construct($userId);
		$this->ruleFactory = new RuleFactory();
	}

	/**
	 * @throws UnknownActionException
	 */
	public function check(string $action, ?AccessibleItem $item = null, $params = null): bool
	{
		$params[MailBaseRule::PERMISSION_ID_KEY] = MailActionDictionary::getActionPermissionMap()[$action] ?? null;

		static $ruleHandler = [];

		if (!isset($ruleHandler[$action]))
		{
			$ruleHandler[$action] = $this->ruleFactory->createFromAction($action, $this);
		}

		$rule = $ruleHandler[$action] ?? null;

		if (!$rule)
		{
			throw new UnknownActionException($action);
		}

		$event = $this->sendEvent(EventDictionary::EVENT_ON_BEFORE_CHECK, $action, $item, $params);
		$isAccess = $event->isAccess();

		if (!is_null($isAccess))
		{
			return $isAccess;
		}

		$isAccess = $rule->execute($item, $params);

		$event = $this->sendEvent(EventDictionary::EVENT_ON_AFTER_CHECK, $action, $item, $params, $isAccess);

		return $event->isAccess() ?? $isAccess;
	}

	abstract protected function loadItem(?int $itemId = null): ?AccessibleItem;

	protected function loadUser(int $userId): AccessibleUser
	{
		return UserModel::createFromId($userId);
	}
}
