<?php

namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\ImOpenLines\V2\Session\HistoryAccess;
use Bitrix\ImOpenLines\V2\Session\HistoryService;
use Bitrix\ImOpenLines\V2\Session\Session as OpenLineSession;
use Bitrix\ImOpenLines\V2\Statistics\SessionListService;
use Bitrix\ImOpenLines\V2\Transfer\QueueTransfer;
use Bitrix\ImOpenLines\V2\Transfer\Transferable;
use Bitrix\ImOpenLines\V2\Transfer\UserTransfer;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

// The access denied phrase is owned by the legacy operator lang file.
Loc::loadMessages(__DIR__ . '/../../operator.php');

class Session extends BaseController
{
	public function getAutoWiredParameters()
	{
		return array_merge([
			new ExactParameter(
				Transferable::class,
				'transfer',
				function ($className, int $transferId) {
					return UserTransfer::getInstance($transferId);
				}
			),
			new ExactParameter(
				Transferable::class,
				'transfer',
				function ($className, string $transferId) {
					return QueueTransfer::getInstance($transferId);
				}
			),
		], parent::getAutoWiredParameters());
	}

	/**
	 * @restMethod imopenlines.v2.Session.answer
	 */
	public function answerAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		if ($operator->answer())
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();

		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.markSpam
	 */
	public function markSpamAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		if ($operator->markSpam())
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();

		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.transfer
	 */
	public function transferAction(\Bitrix\Im\V2\Chat $chat, Transferable $transfer): ?array
	{
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId());

		$result = $operator->transfer([
			'TRANSFER_ID' => $transfer->getTransferId(),
		]);

		if ($result)
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();

		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.skip
	 */
	public function skipAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		if ($operator->skip())
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();

		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.finish
	 */
	public function finishAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		$result = $operator->closeDialog();

		if ($result->isSuccess())
		{
			return ['result' => true];
		}

		$this->addErrors($result->getErrors());

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.finishAnother
	 */
	public function finishAnotherAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		$result = $operator->closeDialogOtherOperator();

		if ($result->isSuccess())
		{
			return ['result' => true];
		}

		$this->addErrors($result->getErrors());

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.join
	 */
	public function joinAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		if ($operator->joinSession())
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();

		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.start
	 */
	public function startAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		if ($operator->startSession())
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();

		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.intercept
	 */
	public function interceptAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());

		if ($operator->interceptSession())
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();

		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.pin
	 */
	public function pinAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());
		$result = $operator->setPinMode();

		if ($result->isSuccess())
		{
			return ['result' => true];
		}

		$this->addErrors($result->getErrors());

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.unpin
	 */
	public function unpinAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());
		$result = $operator->setPinMode(false);

		if ($result->isSuccess())
		{
			return ['result' => true];
		}

		$this->addErrors($result->getErrors());

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.setSilentMode
	 */
	public function setSilentModeAction(\Bitrix\Im\V2\Chat $chat, string $silentMode = 'Y'): ?array
	{
		$currentUser = $this->getCurrentUser();
		$operator = new \Bitrix\ImOpenLines\Operator($chat->getChatId(), (int)$currentUser?->getId());
		$result = $operator->setSilentMode($this->convertCharToBool($silentMode));

		if ($result)
		{
			return ['result' => true];
		}

		$basicError = $operator->getError();

		if (!is_null($basicError))
		{
			$this->addError($basicError->getError());
		}

		return null;
	}

	/**
	 * @restMethod imopenlines.v2.Session.isSilentMode
	 */
	public function isSilentModeAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		$currentUser = $this->getCurrentUser();
		$userId = (int)$currentUser?->getId();

		// Personal mode: return the requesting user's state, not the session owner's.
		return [
			'result' => \Bitrix\ImOpenLines\SilentMode\PersonalSilentMode::isEnabled($userId, $chat->getChatId()),
		];
	}

	/**
	 * @restMethod imopenlines.v2.Session.list
	 */
	public function listAction(
		?int $configId = null,
		array $configIdList = [],
		?int $operatorId = null,
		array $operatorIdList = [],
		?string $source = null,
		array $sourceList = [],
		?string $status = null,
		?string $closeReason = null,
		?string $dateCreateFrom = null,
		?string $dateCreateTo = null,
		?string $dateCloseFrom = null,
		?string $dateCloseTo = null,
		?string $vote = null,
		?string $hasVoteHead = null,
		?string $kpiFirstAnswer = null,
		?string $hasCrm = null,
		?int $waitAnswerFrom = null,
		?int $waitAnswerTo = null,
		?int $waitCloseFrom = null,
		?int $waitCloseTo = null,
		string $order = 'dateCreate',
		string $orderDirection = 'desc',
		int $offset = 0,
		int $limit = 50
	): ?array
	{
		$currentUser = $this->getCurrentUser();
		$userId = (int)$currentUser?->getId();

		$filter = [];

		$configIds = !empty($configIdList) ? $configIdList : $configId;
		if ($configIds !== null)
		{
			$filter['configId'] = $configIds;
		}

		$operatorIds = !empty($operatorIdList) ? $operatorIdList : $operatorId;
		if ($operatorIds !== null)
		{
			$filter['operatorId'] = $operatorIds;
		}

		$sources = !empty($sourceList) ? $sourceList : $source;
		if ($sources !== null)
		{
			$filter['source'] = $sources;
		}

		if ($status !== null)
		{
			$filter['status'] = $status;
		}

		if ($closeReason !== null)
		{
			$filter['closeReason'] = $closeReason;
		}

		if ($dateCreateFrom !== null)
		{
			$filter['dateCreateFrom'] = $dateCreateFrom;
		}

		if ($dateCreateTo !== null)
		{
			$filter['dateCreateTo'] = $dateCreateTo;
		}

		if ($dateCloseFrom !== null)
		{
			$filter['dateCloseFrom'] = $dateCloseFrom;
		}

		if ($dateCloseTo !== null)
		{
			$filter['dateCloseTo'] = $dateCloseTo;
		}

		if ($vote !== null)
		{
			$filter['vote'] = $vote;
		}

		$hasVoteHeadValue = $this->parseBoolFilter($hasVoteHead);
		if ($hasVoteHeadValue !== null)
		{
			$filter['hasVoteHead'] = $hasVoteHeadValue;
		}

		$kpiFirstAnswerValue = $this->parseBoolFilter($kpiFirstAnswer);
		if ($kpiFirstAnswerValue !== null)
		{
			$filter['kpiFirstAnswer'] = $kpiFirstAnswerValue;
		}

		$hasCrmValue = $this->parseBoolFilter($hasCrm);
		if ($hasCrmValue !== null)
		{
			$filter['hasCrm'] = $hasCrmValue;
		}

		if ($waitAnswerFrom !== null)
		{
			$filter['waitAnswerFrom'] = $waitAnswerFrom;
		}

		if ($waitAnswerTo !== null)
		{
			$filter['waitAnswerTo'] = $waitAnswerTo;
		}

		if ($waitCloseFrom !== null)
		{
			$filter['waitCloseFrom'] = $waitCloseFrom;
		}

		if ($waitCloseTo !== null)
		{
			$filter['waitCloseTo'] = $waitCloseTo;
		}

		$service = new SessionListService();
		$result = $service->getList($userId, $filter, $order, $orderDirection, $offset, $this->getLimit($limit));

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * Read-only history slice of an open line session in the V2 Chat.load shape,
	 * paginated by a keyset cursor (lastId = 0 => first page, the most recent messages of the window).
	 *
	 * @restMethod imopenlines.v2.Session.getHistory
	 * @internal
	 */
	public function getHistoryAction(OpenLineSession $session, int $limit = 50, int $lastId = 0): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();

		if (!HistoryAccess::canView($session, $userId))
		{
			$this->addError(new Error(Loc::getMessage('IMOL_OPERATOR_ERROR_ACCESS_DENIED'), 'ACCESS_DENIED'));

			return null;
		}

		if ($lastId <= 0 && $userId > 0)
		{
			\Bitrix\Im\Disk\NoRelationPermission::add((int)$session->getChatId(), $userId);
		}

		return (new HistoryService($session))->getHistory($this->getLimit($limit), max($lastId, 0));
	}
}
