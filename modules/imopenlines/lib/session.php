<?php
namespace Bitrix\ImOpenLines;

use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserConsent\Consent;

use Bitrix\Im\User;
use Bitrix\Im\V2\Entity\User\User as UserV2;
use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\V2\Message\ReadService;
use Bitrix\Im\V2\Message\Params;

use Bitrix\ImOpenLines;
use Bitrix\ImOpenLines\Log\Library;
use Bitrix\Imopenlines\Im\Messages;
use Bitrix\ImOpenLines\Log\EventLog;
use Bitrix\ImOpenLines\Session\Agent;
use Bitrix\ImOpenLines\Session\Update;
use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\ImOpenLines\Model\SessionCheckTable;
use Bitrix\Imopenlines\Model\SessionIndexTable;
use Bitrix\ImOpenLines\Model\SessionAutomaticTasksTable;
use Bitrix\Imopenlines\Model\ConfigAutomaticMessagesTable;
use Bitrix\ImOpenLines\V2\Analytics\Bot\EndBotSessionEventContext;

use Bitrix\ImConnector;
use Bitrix\ImConnector\InteractiveMessage;

Loc::loadMessages(__FILE__);

class Session
{
	private $config = [];
	private $session = [];
	private $user = [];
	private $connectorId = '';

	/* @var Chat */
	public $chat = null;

	/* @var Crm */
	private $crmManager = null;

	/** @var ImOpenLines\Queue\Queue | ImOpenLines\Queue\Evenly | ImOpenLines\Queue\All | ImOpenLines\Queue\Strictly */
	private $queueManager = null;

	private $action = 'none';
	private $joinUserList = [];
	private $isCreated = false;
	private $isAgreementWarningShownNow = false;
	protected $isCloseVote = false;
	protected $isDisabledSendSystemMessage = false;
	protected $isForcedSendVote = false;

	public const RULE_TEXT = 'text';
	public const RULE_FORM = 'form';
	public const RULE_NONE = 'none';

	public const ACTION_NO_ANSWER = 'no_answer';
	public const ACTION_CLOSED = 'closed';
	public const ACTION_NONE = 'none';

	public const MODE_INPUT = 'input';
	public const MODE_OUTPUT = 'output';

	public const CACHE_QUEUE = 'queue';
	public const CACHE_CLOSE = 'close';
	public const CACHE_MAIL = 'mail';
	public const CACHE_INIT = 'init';
	public const CACHE_NO_ANSWER = 'no_answer';

	/** Named lock that serialises the client vote write together with its side effects. */
	private const VOTE_LOCK_PREFIX = 'imopenlines_session_vote_';
	/**
	 * One bounded wait, short on purpose: contention on a single session lasts milliseconds, while this
	 * path is reachable by a public livechat client and every waiting request holds a worker and a
	 * connection. A refusal reaches the client as a VOTE_ERROR from Rest::widgetVoteSend().
	 */
	private const VOTE_LOCK_TIMEOUT = 1;
	/**
	 * How many times one vote request may have its mark changed. Every accepted change is a write, an
	 * event, a pull, a notification and a row in the statistics queue, so the limit bounds a client
	 * looping over like/dislike: the 21st correction of a still open window is refused.
	 */
	private const VOTE_CHANGE_LIMIT = 20;
	private const V2_COMPONENT_VOTE_PARAM = 'imolVote';

	/** New dialog opens. */
	public const STATUS_NEW = 0;
	/** The operator sent the dialog to the queue. */
	public const STATUS_SKIP = 5;
	/** The operator took the dialogue to work. */
	public const STATUS_ANSWER = 10;
	/** The client is waiting for the operator's response. */
	public const STATUS_CLIENT = 20;
	/** The client is waiting for the operator's answer (new question after answer). */
	public const STATUS_CLIENT_AFTER_OPERATOR = 25;
	/** The operator responded to the client. */
	public const STATUS_OPERATOR = 40;
	/** The dialogue in the mode of closing (pending the vote or after auto-answer). */
	public const STATUS_WAIT_CLIENT = 50;
	/** The conversation has ended. */
	public const STATUS_CLOSE = 60;
	/** Spam / forced termination. */
	public const STATUS_SPAM = 65;
	/** Duplicate session. The session is considered closed. */
	public const STATUS_DUPLICATE = 69;
	/** Closed without sending special messages and notifications. */
	public const STATUS_SILENTLY_CLOSE = 75;

	public const VOTE_LIKE = 5;
	public const VOTE_DISLIKE = 1;

	/**
	 * Session constructor.
	 * @param array $config An array describing the setting of an open line.
	 */
	public function __construct($config = null)
	{
		if (is_array($config))
		{
			$this->config = $config;
		}

		Loader::includeModule('im');
	}

	public function setConfig(array $config)
	{
		$this->config = $config;
	}

	public function setCrmManager(Crm $crmManager): self
	{
		$this->crmManager = $crmManager;
		return $this;
	}

	public function getCrmManager(): Crm
	{
		if (!$this->crmManager instanceof Crm)
		{
			$this->crmManager = new Crm($this);
		}
		return $this->crmManager;
	}

	private function isCrmActive(): bool
	{
		return $this->config['CRM'] == 'Y' && $this->getCrmManager()->isLoaded();
	}

	/**
	 * @param ImOpenLines\Queue\Queue | ImOpenLines\Queue\Evenly | ImOpenLines\Queue\All | ImOpenLines\Queue\Strictly $queueManager
	 * @return $this
	 */
	public function setQueueManager(ImOpenLines\Queue\Queue $queueManager): self
	{
		$this->queueManager = $queueManager;
		return $this;
	}

	public function getQueueManager(): ?ImOpenLines\Queue\Queue
	{
		if (!$this->queueManager instanceof ImOpenLines\Queue\Queue)
		{
			$this->queueManager = Queue::initialization($this);
		}
		return $this->queueManager;
	}

	/**
	 * Initialization of the session by the given data.
	 *
	 * @param array $session Array describing the session.
	 * @param array $config An array describing the setting of an open line.
	 * @param Chat $chat The chat instance.
	 */
	public function loadByArray($session, $config, $chat)
	{
		$this->session = $session;
		$this->config = $config;
		$this->chat = $chat;
		$this->connectorId = $session['SOURCE'];

		Debug::addSession($this, __METHOD__);
	}

	/**
	 * Session load method.
	 *
	 * @param array $params
	 * @return bool
	 */
	public function load(array $params)
	{
		$result = false;

		$parsedUserCode = Session\Common::parseUserCode($params['USER_CODE']);
		$parsedUserCodeFail = $parsedUserCode;

		if (empty($params['CONFIG_ID']))
		{
			$params['CONFIG_ID'] = $parsedUserCode['CONFIG_ID'];
		}
		$params['USER_ID'] = $parsedUserCode['CONNECTOR_USER_ID'];
		$params['SOURCE'] = $parsedUserCode['CONNECTOR_ID'];
		$params['CHAT_ID'] = $parsedUserCode['EXTERNAL_CHAT_ID'];

		if (Connector::isEnableGroupByChat($parsedUserCode['CONNECTOR_ID']))
		{
			$parsedUserCode['CONNECTOR_USER_ID'] = 0;

			if (!empty($params['USER_ID']))
			{
				$params['USER_CODE_FAIL'] = $params['USER_CODE'];
			}

			$params['USER_CODE'] = Session\Common::combineUserCode($parsedUserCode);
		}
		else
		{
			//Handles the situation where the group chat feature or not has been changed.
			$parsedUserCodeFail['CONNECTOR_USER_ID'] = 0;
			$params['USER_CODE_FAIL'] = Session\Common::combineUserCode($parsedUserCodeFail);
		}

		$resultStart = $this->start($params);

		if (
			$resultStart->isSuccess() &&
			$resultStart->getResult() === true
		)
		{
			$result = true;
		}

		return $result;
	}

	/**
	 * @param $params
	 * @return Result
	 */
	public function start($params)
	{
		Debug::addSession($this, 'begin ' . __METHOD__, $params);
		$result = new Result();

		$result->setResult(false);

		$this->connectorId = $params['SOURCE'];

		$fields = [];
		$fields['PARENT_ID'] = (int)($params['PARENT_ID'] ?? 0);
		$fields['USER_CODE'] = $params['USER_CODE'];

		$fields['USER_CODE_FAIL'] = $params['USER_CODE_FAIL'];

		$fields['CONFIG_ID'] = (int)$params['CONFIG_ID'];
		$fields['USER_ID'] = (int)$params['USER_ID'];
		$fields['OPERATOR_ID'] = (int)($params['OPERATOR_ID'] ?? 0);
		$params['CRM_TRACE_DATA'] = (string)($params['CRM_TRACE_DATA'] ?? '');
		$params['CRM_SKIP_PHONE_VALIDATE'] = (string)($params['CRM_SKIP_PHONE_VALIDATE'] ?? '');
		$fields['SOURCE'] = (string)($params['SOURCE'] ?? '');
		$fields['MODE'] =  (isset($params['MODE']) && $params['MODE'] === self::MODE_OUTPUT ? self::MODE_OUTPUT : self::MODE_INPUT);
		if (isset($params['PORTAL_ID']) && (int)$params['PORTAL_ID'] > 0)
		{
			$fields['PORTAL_ID'] = (int)$params['PORTAL_ID'];
		}
		$params['DEFERRED_JOIN'] = (isset($params['DEFERRED_JOIN']) && $params['DEFERRED_JOIN'] === 'Y' ? 'Y' : 'N');
		$params['SKIP_CREATE'] = (isset($params['SKIP_CREATE']) && $params['SKIP_CREATE'] === 'Y' ? 'Y' : 'N');
		$params['REOPEN'] = (isset($params['REOPEN']) && $params['REOPEN'] === 'Y' ? 'Y' : 'N');
		$params['CRM_TRACKER_REF'] = (string)($params['CRM_TRACKER_REF'] ?? '');

		//Check open line configuration load
		if (empty($this->config) && !empty($fields['CONFIG_ID']))
		{
			$configManager = new Config();
			$this->config = $configManager->get($fields['CONFIG_ID']);
		}
		//USER_CODE keeps the line the chat started in, that line may be deleted after a transfer
		if (empty($this->config) && $fields['CONFIG_ID'] > 0 && $params['SKIP_CREATE'] === 'Y')
		{
			$actualLineId = Queue::getActualLineId(['USER_CODE' => $fields['USER_CODE']]);
			if ($actualLineId > 0 && $actualLineId !== $fields['CONFIG_ID'])
			{
				$actualConfig = (new Config())->get($actualLineId);
				if (!empty($actualConfig))
				{
					$this->config = $actualConfig;
					$fields['CONFIG_ID'] = $actualLineId;
				}
			}
		}
		if (empty($this->config))
		{
			$result->addError(new Error(
				Loc::getMessage('IMOL_SESSION_ERROR_NO_IMOL_CONFIGURATION'),
				'NO IMOL CONFIGURATION',
				__METHOD__,
				$params
			));
		}

		if ($result->isSuccess())
		{
			if ($this->prepareUserChat($params) !== true || empty($this->chat))
			{
				$result->addError(new Error(Loc::getMessage('IMOL_SESSION_ERROR_NO_CHAT'), 'NO CHAT', __METHOD__, $params));
			}
		}

		if ($result->isSuccess())
		{
			//Load session
			$resultReading = $this->readingSession($fields, $params);

			if ($resultReading->isSuccess())
			{
				if ($resultReading->getResult() == true)
				{
					$result->setResult(true);
				}
				//If you do create a session
				elseif
				(
					$params['SKIP_CREATE'] !== 'Y' &&
					!$this->isCloseVote()
				)
				{
					//Creating a new session
					$resultCreate = $this->createSession($fields, $params);

					if ($resultCreate->isSuccess())
					{
						$result->setResult(true);
					}
					else
					{
						$result->addErrors($resultCreate->getErrors());
					}
				}
			}
			else
			{
				$result->addErrors($resultReading->getErrors());
			}
		}

		Debug::addSession($this, 'end ' . __METHOD__, ['SUCCESS' => $result->isSuccess(), 'ERRORS' => $result->getErrorMessages()]);

		return $result;
	}

	/**
	 * @param $fields
	 * @param $params
	 * @return Result
	 */
	protected function createSession($fields, $params)
	{
		Debug::addSession($this, 'begin ' . __METHOD__, ['fields' => $fields, '$params' => $params]);

		$result = new Result();

		/* CRM BLOCK */
		$crmManager = $this->getCrmManager();

		if ($crmManager->isLoaded())
		{
			$crmManager
				->getFields()
				->setSkipPhoneValidate($params['CRM_SKIP_PHONE_VALIDATE'] === 'Y')
				->setDataFromUser($fields['USER_ID'])
			;
			$crmManager->setModeCreate($this->config['CRM_CREATE']);

			if (Connector::isNeedCRMTracker($fields['SOURCE']))
			{
				$crmManager
					->setSkipCreate()
					->setIgnoreSearchPerson();
			}

			$fields['CRM_TRACE_DATA'] = Crm\Tracker::getTraceData($fields['USER_ID'], $params['CRM_TRACE_DATA']);
		}

		if ($fields['MODE'] === self::MODE_OUTPUT)
		{
			$previousSessionBlock = ReplyBlock::getBlockFromPreviousSession($fields);
		}

		$fields['CHAT_ID'] = $this->chat->getData('ID');

		if ($this->chat->isNowCreated())
		{
			$fields['START_ID'] = 0;
			$fields['IS_FIRST'] = 'Y';
			$fields['LAST_SEND_MAIL_ID'] = 0;
		}
		else
		{
			$fields['START_ID'] = $this->chat->getData('LAST_MESSAGE_ID')+1;
			$fields['IS_FIRST'] = 'N';
			$fields['LAST_SEND_MAIL_ID'] = $this->chat->getData('LAST_MESSAGE_ID');

			$this->chat->join($fields['USER_ID']);
		}

		if (!empty($params['USER_LANG']))
		{
			$fields['USER_LANG'] = $params['USER_LANG'];
		}
		else
		{
			$fields['USER_LANG'] = $this->getConfigLanguage();
		}

		if ($fields['MODE'] == self::MODE_OUTPUT)
		{
			$fields['STATUS'] = self::STATUS_ANSWER;
		}

		if (UserV2::getInstance((int)$fields['USER_ID'])->isConnector())
		{
			$fields['DATE_FIRST_LAST_USER_ACTION'] = new DateTime();
		}

		$resultAdd = Model\SessionTable::add($fields);
		if ($resultAdd->isSuccess())
		{
			$this->isCreated = true;
			$fields['SESSION_ID'] = $resultAdd->getId();

			$resultAddSessionCheck = Model\SessionCheckTable::add([
				'SESSION_ID' => $fields['SESSION_ID'],
				'DATE_CLOSE' => (new DateTime())->add('180 SECONDS'),
				'DATE_QUEUE' => (new DateTime())->add('180 SECONDS'),
			]);

			if ($resultAddSessionCheck->isSuccess())
			{
				$this->session = $fields;
				$this->session['ID'] = $this->session['SESSION_ID'] = $fields['SESSION_ID'];

				$queueManager = $this->getQueueManager();
				$queueManager
					->enableGroupChat(Connector::isEnableGroupByChat($fields['SOURCE']))
					->setCrmManager($crmManager)
				;

				$isExpectationBound = false;
				if ($fields['MODE'] == self::MODE_INPUT)
				{
					if (
						!empty($params['CRM_TRACKER_REF'])
						&& $this->isCrmActive()
					)
					{
						$tracker = new ImOpenLines\Tracker();
						$isExpectationBound = $tracker
							->setSession($this)
							->bindExpectationToChat(
								$params['CRM_TRACKER_REF'],
								$this->chat,
								$params['CRM_TRACKER_EXPECTATION'] ?? null
							)
						;
					}
				}

				$resultQueue = $queueManager->createSession($fields['OPERATOR_ID']);

				$this->session['JOIN_BOT'] = $resultQueue['JOIN_BOT'];
				$this->session['OPERATOR_ID'] = $fields['OPERATOR_ID'] = $params['OPERATOR_ID'] = $resultQueue['OPERATOR_ID'];
				$fields['DATE_OPERATOR'] = $resultQueue['DATE_OPERATOR'];
				$fields['QUEUE_HISTORY'] = $this->session['QUEUE_HISTORY'] = $resultQueue['QUEUE_HISTORY'];
				$dateNoAnswer = $resultQueue['DATE_NO_ANSWER'];
				$undistributed = $resultQueue['UNDISTRIBUTED'] == true? 'Y' : 'N';
				$dateQueue = $resultQueue['DATE_QUEUE'];
				$this->joinUserList = $resultQueue['OPERATOR_LIST'];
				$fields['OPERATOR_FROM_CRM'] = $resultQueue['OPERATOR_CRM'] == true? 'Y' : 'N';

				//Send message
				if ($fields['PARENT_ID'] > 0)
				{
					$messageId = Messages\Session::sendMessageStartSessionByMessage(
						$fields['CHAT_ID'],
						$fields['SESSION_ID'],
						$fields['PARENT_ID']
					);
				}
				else
				{
					if (isset($params['CONNECTOR']['message']['extraData']['SOURCE_SESSION_ID']))
					{
						$parentSession = SessionTable::getRow([
							'select' => ['USER_CODE'],
							'filter' => ['ID' => $params['CONNECTOR']['message']['extraData']['SOURCE_SESSION_ID']],
						]);

						$messageId = Messages\Session::sendMessageNewMultidialog(
							$fields['SESSION_ID'],
							$fields['CHAT_ID'],
							(string)$parentSession['USER_CODE'],
							$params['CONNECTOR']['message']['extraData']['SOURCE_SESSION_ID']
						);
					}
					else
					{
						$messageId = Messages\Session::sendMessageStartSession($fields['CHAT_ID'], $fields['SESSION_ID']);
					}
				}

				// Send previous session history if client has sessions with different config
				if (Helpers\Filter::isFdcMode())
				{
					Messages\Session::sendMessagePreviousSessionHistory(
						$fields['CHAT_ID'],
						$fields['USER_ID'],
						$fields['CONFIG_ID']
					);
				}
				//END Send message

				if ($this->chat->isNowCreated())
				{
					if (intval($messageId) < 0)
					{
						$messageId = 0;
					}

					$fields['START_ID'] = $messageId;
				}

				$this->chat->updateFieldData([Chat::FIELD_SESSION => [
					'ID' => $fields['SESSION_ID'],
					'DATE_CREATE' => new DateTime()
				]]);

				if ($fields['MODE'] == self::MODE_INPUT)
				{
					/* CLOSED LINE */
					if ($this->config['ACTIVE'] == 'N')
					{
						$this->action = self::ACTION_CLOSED;
						$fields['WORKTIME'] = 'N';
						$fields['WAIT_ACTION'] = 'Y';
					}
					elseif (!(new AutomaticAction\WorkTime($this))->isWorkTimeLine())
					{
						$fields['WORKTIME'] = 'N';
						if ($this->session['JOIN_BOT'])
						{
							$this->resetActionAll();
						}
						else
						{
							$fields['WAIT_ACTION'] = 'Y';
						}
					}
				}
				elseif ($fields['MODE'] == self::MODE_OUTPUT)
				{
					if ($this->config['ACTIVE'] == 'N')
					{
						$this->action = self::ACTION_CLOSED;
						$fields['WORKTIME'] = 'N';
						$fields['WAIT_ACTION'] = 'Y';
					}
					if ($fields['OPERATOR_ID'])
					{
						if ($this->chat->getData('AUTHOR_ID') == 0)
						{
							$this->chat->answer($fields['OPERATOR_ID'], true);
							$this->chat->join($fields['OPERATOR_ID']);
						}
					}
					$fields['WAIT_ANSWER'] = 'N';
				}

				$sessionId = $fields['SESSION_ID'];
				unset($fields['SESSION_ID']);
				Model\SessionTable::update($sessionId, $fields);
				$fields['SESSION_ID'] = $sessionId;

				if (
					$fields['MODE'] == self::MODE_INPUT
					&& !empty($this->joinUserList)
					&& $params['DEFERRED_JOIN'] == 'N'
				)
				{
					$this->chat->sendJoinMessage($this->joinUserList, $fields['OPERATOR_FROM_CRM']);
					$this->chat->join($this->joinUserList);
					$this->joinUserList = [];
				}

				$dateClose = new DateTime();

				if ($fields['MODE'] == self::MODE_INPUT)
				{
					$dateClose->add('1 MONTH');
				}
				else
				{
					$dateClose->add($this->config['AUTO_CLOSE_TIME'] . ' SECONDS');
				}

				Model\SessionCheckTable::update($fields['SESSION_ID'], [
					'DATE_CLOSE' => $dateClose,
					'DATE_QUEUE' => $dateQueue,
					'DATE_NO_ANSWER' => $dateNoAnswer,
					'UNDISTRIBUTED' => $undistributed,
				]);

				$sessionManager = Model\SessionTable::getByIdPerformance($fields['SESSION_ID']);
				$this->session = $sessionManager->fetch();
				$this->session['SESSION_ID'] = $this->session['ID'];

				$this->session['CHECK_DATE_CLOSE'] = $dateClose;
				$this->session['CHECK_DATE_QUEUE'] = $dateQueue;

				if (
					!empty($previousSessionBlock['BLOCK_DATE']) &&
					!empty($previousSessionBlock['BLOCK_REASON'])
				)
				{
					ReplyBlock::add($fields['SESSION_ID'], $this->chat, $previousSessionBlock);
				}
				elseif (Loader::includeModule('imconnector'))
				{
					$channelType = null;
					if (in_array($fields['SOURCE'], array_keys(\Bitrix\ImConnector\Library::TIME_LIMIT_CHANNELS_RESTRICTIONS), true))
					{
						$connectorStatusData = ImConnector\Status::getInstance($fields['SOURCE'], $fields['CONFIG_ID'])->getData();
						if (!empty($connectorStatusData))
						{
							$channelType = $connectorStatusData['channelType'];
						}
					}
					$limit = \Bitrix\ImConnector\Connector::getReplyLimit($fields['SOURCE'], $channelType);
					if ($limit)
					{
						if (!empty($limit['BLOCK_DATE']))
						{
							$limit['BLOCK_DATE'] = (new DateTime())->add($limit['BLOCK_DATE'] . ' SECONDS');

							ReplyBlock::add($fields['SESSION_ID'], $this->chat, $limit);
						}

						Messages\Session::sendMessageTimeLimit($fields['CHAT_ID'], $limit['BLOCK_REASON']);
					}
				}


				/* BLOCK STATISTIC*/
				ConfigStatistic::getInstance((int)$fields['CONFIG_ID'])->addInWork()->addSession();

				/* CRM BLOCK */
				if ($fields['MODE'] == self::MODE_INPUT)
				{
					if (empty($params['CRM_TRACKER_REF']))
					{
						if (
							!Connector::isEnableGroupByChat($fields['SOURCE'])
							&& $this->isCrmActive()
						)
						{
							$crmManager->getFields()->setTitle($this->chat->getData('TITLE'));

							$crmManager->setDefaultFlags();
							$crmManager->registrationChanges();
							$crmManager->sendCrmImMessages();
						}
						else
						{
							$crmManager->setDefaultFlags();
						}
					}
					elseif ($isExpectationBound)
					{
						$crmManager->sendCrmImMessages();
					}
				}
				elseif ($fields['MODE'] == self::MODE_OUTPUT)
				{
					if ($this->isCrmActive())
					{
						$crmManager->getFields()->setTitle($this->chat->getData('TITLE'));

						if (
							$params['REOPEN'] === 'Y'
							|| $fields['IS_FIRST'] === 'N'
						)
						{
							$crmManager->setSkipAutomationTriggerFirstMessage();
						}

						$crmManager->registrationChanges();
						$crmManager->sendCrmImMessages();
					}
				}

				/* Event */
				$eventData = [];
				$eventData['SESSION'] = $this->session;
				$eventData['RUNTIME_SESSION'] = $this;
				$eventData['CONFIG'] = $this->config;
				$eventData['CONNECTOR'] = $params['CONNECTOR'];

				$event = new Main\Event('imopenlines', 'OnSessionStart', $eventData);
				$event->send();
				/* END Event */

				if (Connector::isLiveChat($this->session['SOURCE']))
				{
					$parsedUserCode = Session\Common::parseUserCode($this->session['USER_CODE']);

					\Bitrix\Pull\Event::add($this->session['USER_ID'], [
						'module_id' => 'imopenlines',
						'command' => 'sessionStart',
						'params' => [
							'chatId' => (int)$parsedUserCode['EXTERNAL_CHAT_ID'],
							'sessionId' => (int)$this->session['ID'],
						]
					]);
				}
			}
			else
			{
				$result->addErrors($resultAddSessionCheck->getErrors());
			}

		}
		else
		{
			$result->addErrors($resultAdd->getErrors());
		}

		Debug::addSession($this, 'end ' . __METHOD__, ['SUCCESS' => $result->isSuccess(), 'ERRORS' => $result->getErrorMessages()]);

		return $result;
	}


	/**
	 * @param array $params
	 * @param bool $sessionOnly
	 * @return Result
	 */
	public function getLast(array $params, bool $sessionOnly = false): Result
	{
		$result = new Result();

		if (empty($params['USER_CODE']))
		{
			$result->addError(new Error(Loc::getMessage('IMOL_SESSION_ERROR_NO_USER_CODE'), 'NO IMOL CONFIGURATION', __METHOD__));
		}

		$select = Model\SessionTable::getSelectFieldsPerformance();

		$orm = Model\SessionTable::getList([
			'select' => $select,
			'filter' => [
				'=USER_CODE' => $params['USER_CODE'],
			],
			'order' => ['ID' => 'DESC'],
			'limit' => 1
		]);
		$loadSession = $orm->fetch();

		if (!empty($loadSession))
		{
			$loadSession['SESSION_ID'] = $loadSession['ID'];
			$this->session = $loadSession;

			if (!$sessionOnly)
			{
				$this->chat = new Chat($this->session['CHAT_ID']);

				$configManager = new Config();
				$this->config = $configManager->get($loadSession['CONFIG_ID']);
			}
		}
		else
		{
			$result->addError(new Error(Loc::getMessage('IMOL_SESSION_ERROR_NO_LAST_SESSION'), 'NO LAST SESSION', __METHOD__));
		}

		return $result;
	}

	/**
	 * @param $fields
	 * @param $params
	 * @return Result
	 */
	protected function readingSession($fields, $params)
	{
		Debug::addSession($this, 'begin ' . __METHOD__, ['fields' => $fields, '$params' => $params]);

		$result = new Result();

		$result->setResult(false);
		$configManager = new Config();

		$loadSession = false;

		$select = Model\SessionTable::getSelectFieldsPerformance();
		$select['CHECK_DATE_CLOSE'] = 'CHECK.DATE_CLOSE';
		$select['CHECK_DATE_QUEUE'] = 'CHECK.DATE_QUEUE';

		$userCode = $fields['USER_CODE'];

		if (!empty($fields['USER_CODE_FAIL']))
		{
			$userCode = [$fields['USER_CODE'], $fields['USER_CODE_FAIL']];
		}

		$orm = Model\SessionTable::getList([
			'select' => $select,
			'filter' => [
				'=USER_CODE' => $userCode,
				'=CLOSED' => 'N'
			],
			'order' => ['ID' => 'DESC']
		]);
		while ($row = $orm->fetch())
		{
			if (
				$row['USER_CODE'] === $fields['USER_CODE']
				&& empty($loadSession)
			)
			{
				$loadSession = $row;
			}
			//Closes non-closed sessions
			else
			{
				try
				{
					self::closeDuplicate(
						[
							'ID' => $row['ID'],
							'CHAT_ID' => $row['CHAT_ID'],
							'OPERATOR_ID' => $row['OPERATOR_ID'],
						],
						[
							'CHAT_ID' => $loadSession['CHAT_ID'],
							'OPERATOR_ID' => $loadSession['OPERATOR_ID'],
						]
					);
				}
				catch (\Bitrix\Main\SystemException $e)
				{
				}
			}
		}

		//There is no open session, but the session voting
		if (
			empty($loadSession)
			&&
			(
				(
					isset($params['VOTE_SESSION'])
					&& $params['VOTE_SESSION'] === 'Y'
				)
				||
				(
					isset($params['REOPEN'])
					&& $params['REOPEN'] === 'Y'
				)
			)
		)
		{
			$select = Model\SessionTable::getSelectFieldsPerformance();
			$select['CHECK_DATE_CLOSE'] = 'CHECK.DATE_CLOSE';
			$select['CHECK_DATE_QUEUE'] = 'CHECK.DATE_QUEUE';

			$orm = Model\SessionTable::getList([
				'select' => $select,
				'filter' => [
					'=USER_CODE' => $fields['USER_CODE'],
					'=CLOSED' => 'Y',
				],
				'order' => ['ID' => 'DESC']
			]);
			$loadSession = $orm->fetch();

			if (
				empty($loadSession) ||
				$loadSession['WAIT_VOTE'] !== 'Y'
			)
			{
				$loadSession = false;
			}
		}

		if ($loadSession)
		{
			$loadSession['SESSION_ID'] = $loadSession['ID'];
			$this->session = $loadSession;

			if (isset($params['VOTE_SESSION']) && $params['VOTE_SESSION'] === 'Y')
			{
				$this->session['VOTE_SESSION'] = true;
			}

			if ($fields['CONFIG_ID'] !== $this->session['CONFIG_ID'])
			{
				$this->config = $configManager->get($this->session['CONFIG_ID']);
				if (!empty($this->config))
				{
					$fields['CONFIG_ID'] = $this->session['CONFIG_ID'];
				}
				else
				{
					$result->addError(new Error(
						Loc::getMessage('IMOL_SESSION_ERROR_NO_IMOL_CONFIGURATION'),
						'NO IMOL CONFIGURATION',
						__METHOD__,
						$params
					));
				}
			}

			if ($result->isSuccess())
			{
				if (
					!($this->chat instanceof Chat)
					|| ($this->chat->getData('ID') != $this->session['CHAT_ID'])
				)
				{
					$this->chat = new Chat($this->session['CHAT_ID']);
				}

				if (
					isset($params['VOTE_SESSION'])
					&& $params['VOTE_SESSION'] === 'Y'
					&& isset($loadSession['DATE_CLOSE_VOTE'])
					&& $loadSession['DATE_CLOSE_VOTE'] instanceof DateTime
					&& $loadSession['DATE_CLOSE_VOTE']->getTimestamp() < time()
				)
				{
					$this->isCloseVote = true;
				}

				if (!$this->isCloseVote())
				{
					//If the session is closed and voting
					if (
						isset($params['VOTE_SESSION'])
						&& $params['VOTE_SESSION'] === 'Y'
						&& $loadSession['CLOSED'] === 'Y'
					)
					{
						Messages\Session::sendMessageReopenSession($this->session['CHAT_ID'], $this->session['SESSION_ID']);

						//statistics
						ConfigStatistic::getInstance((int)$this->session['CONFIG_ID'])->deleteClosed()->addInWork();

						$dateClose = new DateTime();

						$fullCloseTime = $this->config['FULL_CLOSE_TIME'];
						if (!empty($fullCloseTime))
						{
							$dateClose->add($fullCloseTime . ' MINUTES');
						}

						$connectorChatId = (int)Chat::parseLinesChatEntityId($this->session['USER_CODE'])['connectorChatId'];
						$this->session['END_ID'] = 0;
						$this->session['CLOSED'] = 'N';
						$this->session['DATE_CLOSE'] = '';
						$this->session['DATE_OPERATOR_CLOSE'] = null;
						// The dialog is back in work, so the stored duration belongs to no close any more:
						// left in place it would survive the next automatic close, which recomputes the
						// duration only while it is empty.
						$this->session['TIME_CLOSE'] = 0;
						$this->session['WAIT_ANSWER'] = 'N';
						$this->session['WAIT_ACTION'] = 'Y';
						$this->session['PAUSE'] = 'N';
						$this->session['LAST_SEND_MAIL_ID'] = Session::getLastMessageId($connectorChatId);

						Model\SessionTable::update($loadSession['ID'], [
							'END_ID' => $this->session['END_ID'],
							'CLOSED' => $this->session['CLOSED'],
							'DATE_CLOSE' => $this->session['DATE_CLOSE'],
							'DATE_OPERATOR_CLOSE' => $this->session['DATE_OPERATOR_CLOSE'],
							'TIME_CLOSE' => $this->session['TIME_CLOSE'],
							'WAIT_ANSWER' => $this->session['WAIT_ANSWER'],
							'WAIT_ACTION' => $this->session['WAIT_ACTION'],
							'PAUSE' => $this->session['PAUSE'],
							'LAST_SEND_MAIL_ID' => $this->session['LAST_SEND_MAIL_ID'],
						]);
						try
						{
							Model\SessionCheckTable::add([
								'SESSION_ID' => $this->session['SESSION_ID'],
								'DATE_CLOSE' => $dateClose
							]);
						}
						catch (Main\DB\DuplicateEntryException $ex)
						{
							Model\SessionCheckTable::update($this->session['SESSION_ID'], ['DATE_CLOSE' => $dateClose]);
						}

						$this->chat->sendJoinMessage($this->joinUserList);
						$this->chat->join($this->session['OPERATOR_ID'], true, true);
						$this->chat->update(['AUTHOR_ID' => $this->session['OPERATOR_ID']]);

						$this->chat->updateFieldData([
							Chat::FIELD_SESSION => [
								'ID' => $this->session['SESSION_ID'],
								'PAUSE' => $this->session['PAUSE'],
								'WAIT_ANSWER' => $this->session['WAIT_ANSWER'],
								'DATE_CREATE' => $this->session['DATE_CREATE']->getTimestamp()
							],
						]);

						Connector::onSessionStart(
							new Main\Event('imopenlines', 'OnSessionStart', ['RUNTIME_SESSION' => $this])
						);
					}
					elseif (!$this->chat->isNowCreated())
					{
						$this->chat->join($fields['USER_ID']);
					}

					if (
						!empty($fields['OPERATOR_ID'])
						&& empty($this->session['OPERATOR_ID'])
					)
					{
						$config = $configManager->get($this->session['CONFIG_ID']);
						if (
							$this->session['STATUS'] >= self::STATUS_ANSWER
							|| $config['WELCOME_BOT_ENABLE'] != 'Y'
							|| $config['WELCOME_BOT_ID'] != $fields['OPERATOR_ID']
						)
						{
							$resultChatAnswer = $this->chat->answer($fields['OPERATOR_ID'], true);
							$this->answer($fields['OPERATOR_ID']);
						}
					}

					$result->setResult(true);
				}
			}
		}

		Debug::addSession($this, 'end ' . __METHOD__, ['SUCCESS' => $result->isSuccess(), 'ERRORS' => $result->getErrorMessages()]);

		return $result;
	}

	/**
	 * @param $params
	 * @param int $count
	 * @return bool
	 */
	private function prepareUserChat($params, $count = 0)
	{
		$result = false;

		$resultUserRelation = \Bitrix\Imopenlines\Model\UserRelationTable::getByPrimary($params['USER_CODE'])->fetch();
		if ($resultUserRelation)
		{
			if ($resultUserRelation['CHAT_ID'])
			{
				if (
					!($this->chat instanceof Chat)
					|| ($this->chat->getData('ID') != $resultUserRelation['CHAT_ID'])
				)
				{
					$this->chat = new Chat($resultUserRelation['CHAT_ID'], $params);
				}
				if ($this->chat->isDataLoaded())
				{
					$this->user = $resultUserRelation;

					$result = true;
				}
			}
			elseif ($count <= 20)
			{
				usleep(500);
				$result = $this->prepareUserChat($params, ++$count);
			}
		}
		elseif ($params['SKIP_CREATE'] != 'Y')
		{
			$params['USER_ID'] = intval($params['USER_ID']);
			\Bitrix\Imopenlines\Model\UserRelationTable::add([
				'USER_CODE' => $params['USER_CODE'],
				'USER_ID' => $params['USER_ID']
			]);

			if (!($this->chat instanceof Chat))
			{
				$this->chat = new Chat();
			}
			if (!$this->chat->isDataLoaded())
			{
				$this->chat->load([
					'USER_CODE' => $params['USER_CODE'],
					'USER_ID' => $params['USER_ID'],
					'LINE_NAME' => $this->config['LINE_NAME'],
					'CONNECTOR' => $params['CONNECTOR'],
				]);
			}
			if ($this->chat->isDataLoaded())
			{
				\Bitrix\Imopenlines\Model\UserRelationTable::update($params['USER_CODE'], ['CHAT_ID' => $this->chat->getData('ID')]);

				$resultUserRelation = [
					'USER_CODE' => $params['USER_CODE'],
					'USER_ID' => $params['USER_ID'],
					'CHAT_ID' => $this->chat->getData('ID'),
					'AGREES' => 'N',
					'SHOWN_AGREEMENT_ID' => 0,
				];
				$this->user = $resultUserRelation;

				$result = true;
			}
			else
			{
				\Bitrix\Imopenlines\Model\UserRelationTable::delete($params['USER_CODE']);
			}
		}

		return $result;
	}

	/**
	 * @param $userId
	 */
	public function answer($userId): void
	{
		$this->setOperatorId($userId, false, false);

		if ($this->getData('CRM_ACTIVITY_ID') > 0)
		{
			$closeDate = new Main\Type\DateTime();
			$closeDate->add((int)$this->getConfig('AUTO_CLOSE_TIME').' SECONDS');
			$closeDate->add('1 DAY');

			$crmManager = $this->getCrmManager();
			if ($crmManager->isLoaded())
			{
				$crmManager->setSessionAnswered(['DATE_CLOSE' => $closeDate]);
				$crmManager->executeAutomationAnswerControlTrigger($this);
				$crmManager->executeAutomationAnswerTrigger($this);

				if (
					$this->getData('STATUS') <= self::STATUS_ANSWER
					&& $this->getConfig('IGNORE_WELCOME_FORM_RESPONSIBLE') === 'Y'
					&& $this->getData('CRM') === 'Y'
				)
				{
					$crmEntitiesManager = \Bitrix\ImOpenLines\Crm\Common::getActivityBindings($this->getData('CRM_ACTIVITY_ID'));
					if($crmEntitiesManager->isSuccess())
					{
						$entities = $crmEntitiesManager->getData();
						if (isset($entities[Crm::ENTITY_CONTACT]) && $entities[Crm::ENTITY_CONTACT] > 0)
						{
							$entity = new \CCrmContact(false);
							$data = ['ASSIGNED_BY_ID' => $crmManager->getResponsibleCrmId()];
							$entity->Update($entities[Crm::ENTITY_CONTACT], $data);
						}
						if (isset($entities[Crm::ENTITY_LEAD]) && $entities[Crm::ENTITY_LEAD] > 0)
						{
							$entity = new \CCrmLead(false);
							$data = ['ASSIGNED_BY_ID' => $crmManager->getResponsibleCrmId()];
							$entity->Update($entities[Crm::ENTITY_LEAD], $data);
						}
					}
				}
			}
		}

		$sessionUpdate = [
			'OPERATOR_ID' => $userId,
			'WAIT_ACTION' => 'N',
			'WAIT_ANSWER' => 'N',
			'SEND_NO_ANSWER_TEXT' => 'Y'
		];
		if (
			$this->getData('DATE_OPERATOR_ANSWER') <= 0 &&
			!UserV2::getInstance((int)$userId)->isBot()
		)
		{
			$currentDate = new DateTime();
			$sessionUpdate['DATE_OPERATOR_ANSWER'] = $currentDate;

			$dateCreate = $this->getData('DATE_CREATE');
			if (
				!empty($dateCreate) &&
				$dateCreate instanceof DateTime
			)
			{
				$sessionUpdate['TIME_ANSWER'] = $currentDate->getTimestamp()-$dateCreate->getTimestamp();
			}
		}

		$this->update($sessionUpdate);
	}

	/**
	 * @param bool $active
	 * @return bool
	 */
	public function pause(bool $active = true): bool
	{
		$update = [
			'PAUSE' => $active? 'Y': 'N',
		];
		if ($active === true)
		{
			$update['WAIT_ACTION'] = 'N';
		}
		$this->update($update);

		Debug::addSession($this,  __METHOD__);
		$this->addEventToLog(Library::EVENT_SESSION_PAUSE);

		return true;
	}

	/**
	 * @return bool
	 */
	public function markSpam()
	{
		$this->update([
			'SPAM' => 'Y',
			'WAIT_ANSWER' => 'N',
			'DATE_MODIFY' => new DateTime(),
			'SKIP_RECENT' => 'Y'
		]);

		Debug::addSession($this,  __METHOD__);
		$this->addEventToLog(Library::EVENT_SESSION_SPAM);

		return true;
	}

	/**
	 * End of dialog.
	 *
	 * @param bool $auto
	 * @param bool $force
	 * @param bool $hideChat
	 * @return bool
	 */
	public function finish(bool $auto = false, bool $force = false, bool $hideChat = true): bool
	{
		$result = false;

		Debug::addSession($this, __METHOD__, ['auto' => $auto, 'force' => $force, 'hideChat' => $hideChat]);
		if (!empty($this->session))
		{
			KpiManager::setSessionLastKpiMessageAnswered($this->session['ID']);

			$update = [];
			$messages = [];

			// Kept before $force rewrites the in-memory state: only a real N -> Y transition is publishable.
			$wasClosed = $this->session['CLOSED'];

			if ($force)
			{
				$this->session['CLOSED'] = 'Y';
				$update['FORCE_CLOSE'] = 'Y';
			}

			$currentDate = new DateTime();

			if ($this->session['CHAT_ID'])
			{
				$chatData = ChatTable::getById($this->session['CHAT_ID'])->fetch();
				$lastMessageId = $chatData['LAST_MESSAGE_ID'];
			}
			else
			{
				$lastMessageId = 0;
			}

			/*
			todo: Make new session config option to select that to set as date close - the last massage date either current date.
			if (
				$auto
				&& $lastMessageId > 0
			)
			{
				$messageData = MessageTable::getById($lastMessageId)->fetch();
				if ($messageData)
				{
					$currentDate = clone $messageData['DATE_CREATE'];
				}
			}
			*/

			$userViewChat = \CIMContactList::InRecent($this->session['OPERATOR_ID'], IM_MESSAGE_OPEN_LINE, $this->session['CHAT_ID']);

			if (
				$this->session['CLOSED'] === 'Y'
				|| $this->session['SPAM'] === 'Y'
				|| ($this->session['WAIT_ACTION'] === 'Y' && $this->session['WAIT_ANSWER'] === 'N')
			)
			{
				$update['WAIT_ACTION'] = 'N';
				$update['WAIT_ANSWER'] = 'N';
				$update['CLOSED'] = 'Y';

				$messages[] = [
					'TO_CHAT_ID' => $this->session['CHAT_ID'],
					'FROM_USER_ID' => $this->session['OPERATOR_ID'],
					'MESSAGE' => Loc::getMessage('IMOL_SESSION_CLOSE_FINAL'),
					'SYSTEM' => 'Y',
					'SKIP_USER_CHECK' => 'Y',
					'RECENT_ADD' => $userViewChat ? 'Y' : 'N',
					'PARAMS' => [
						Params::STYLE_CLASS => 'bx-messenger-content-item-ol-end',
						Params::TYPE => 'lines',
						Params::COMPONENT_ID => 'bx-imopenlines-message',
						MessageParameter::IMOL_VOTE_SID => $this->session['ID'],
						MessageParameter::IMOL_VOTE_USER => $this->session['VOTE'],
						MessageParameter::IMOL_VOTE_HEAD => $this->session['VOTE_HEAD'],
						MessageParameter::IMOL_COMMENT_HEAD => htmlspecialcharsbx($this->session['COMMENT_HEAD']),
					]
				];
			}
			else
			{
				if ($this->config['ACTIVE'] === 'N')
				{
					$update['WAIT_ACTION'] = 'N';
					$update['WAIT_ANSWER'] = 'N';
					$update['CLOSED'] = 'Y';

					$messages[] = [
						'TO_CHAT_ID' => $this->session['CHAT_ID'],
						'FROM_USER_ID' => $this->session['OPERATOR_ID'],
						'RECENT_ADD' => $userViewChat? 'Y': 'N',
						'MESSAGE'=> Loc::getMessage('IMOL_SESSION_CLOSE_FINAL'),
						'SYSTEM'=> 'Y',
						'PARAMS' => [
							Params::STYLE_CLASS => 'bx-messenger-content-item-ol-end',
							Params::TYPE => 'lines',
							Params::COMPONENT_ID => 'bx-imopenlines-message',
							MessageParameter::IMOL_VOTE_SID => $this->session['ID'],
							MessageParameter::IMOL_VOTE_USER => $this->session['VOTE'],
							MessageParameter::IMOL_VOTE_HEAD => $this->session['VOTE_HEAD'],
							MessageParameter::IMOL_COMMENT_HEAD => htmlspecialcharsbx($this->session['COMMENT_HEAD']),
						]
					];
				}
				else
				{
					$enableSystemMessage = $this->isEnableSendSystemMessage();
					$waitAction = false;

					if (
						$enableSystemMessage
						&& (
							(
								$auto
								&& $this->config['AUTO_CLOSE_RULE'] === self::RULE_TEXT
							)
							|| (
								!$auto
								&& $this->config['CLOSE_RULE'] === self::RULE_TEXT
							)
						)
					)
					{
						$messageCloseText = $this->config['CLOSE_TEXT'];

						if ($auto)
						{
							$this->chat->update([
								Chat::getFieldName(Chat::FIELD_SILENT_MODE) => 'N'
							]);

							$messageCloseText = $this->config['AUTO_CLOSE_TEXT'];
						}

						$messages[] = [
							'TO_CHAT_ID' => $this->session['CHAT_ID'],
							'FROM_USER_ID' => $this->session['OPERATOR_ID'],
							'MESSAGE' => $messageCloseText,
							'RECENT_ADD' => $userViewChat? 'Y': 'N',
							'SYSTEM' => 'Y',
							'IMPORTANT_CONNECTOR' => 'Y',
							'NO_SESSION_OL' => 'Y',
							'PARAMS' => [
								Params::STYLE_CLASS => 'bx-messenger-content-item-ol-output',
								Params::TYPE => 'lines',
								Params::COMPONENT_ID => 'bx-imopenlines-message',
								MessageParameter::IMOL_FORM => 'history',
							]
						];

						$update['WAIT_ACTION'] = 'Y';
						$update['WAIT_ANSWER'] = 'N';
						$waitAction = true;
					}

					if (
						(
							$enableSystemMessage === true
							|| $this->isForcedSendVote === true
						)
						&& $this->config['VOTE_MESSAGE'] === 'Y'
						&& $this->session['CHAT_ID']
						&& empty($this->session['VOTE'])
					)
					{
						$paramsDateCloseVote = '';

						if (
							!empty((int)$this->config['VOTE_TIME_LIMIT'])
							&& (int)$this->config['VOTE_TIME_LIMIT'] > 0
						)
						{
							$dateCloseVote = new DateTime();
							$dateCloseVote->add(((int)$this->config['VOTE_TIME_LIMIT']) . ' SECONDS');
							$update['DATE_CLOSE_VOTE'] = $dateCloseVote;

							$paramsDateCloseVote = date('c', $dateCloseVote->getTimestamp());
						}

						$messages[] = [
							'TO_CHAT_ID' => $this->session['CHAT_ID'],
							'FROM_USER_ID' => $this->session['OPERATOR_ID'],
							'MESSAGE' => $this->config['VOTE_MESSAGE_2_TEXT'],
							'SYSTEM' => 'Y',
							'RECENT_ADD' => $userViewChat ? 'Y' : 'N',
							'IMPORTANT_CONNECTOR' => 'Y',
							'NO_SESSION_OL' => 'Y',
							'PARAMS' => [
								MessageParameter::IMOL_VOTE => $this->session['ID'], //todo: Stay here sessionID for compatibility with old client. Replace it with 'none' string
								MessageParameter::IMOL_VOTE_SID => $this->session['ID'],
								MessageParameter::IMOL_VOTE_TEXT => $this->config['VOTE_MESSAGE_1_TEXT'],
								MessageParameter::IMOL_VOTE_LIKE => $this->config['VOTE_MESSAGE_1_LIKE'],
								MessageParameter::IMOL_VOTE_DISLIKE => $this->config['VOTE_MESSAGE_1_DISLIKE'],
								MessageParameter::IMOL_DATE_CLOSE_VOTE => (string)$paramsDateCloseVote,
								MessageParameter::IMOL_TIME_LIMIT_VOTE => (string)$this->config['VOTE_TIME_LIMIT'],
								MessageParameter::IMOL_FORM => 'like',
								Params::STYLE_CLASS => 'bx-messenger-content-item-ol-output bx-messenger-content-item-vote',
								Params::TYPE => 'lines',
								Params::COMPONENT_ID => 'bx-imopenlines-message',
								Params::COMPONENT_PARAMS => [MessageParameter::IMOL_FORM => 'like'],
							]
						];

						$update['WAIT_ACTION'] = 'Y';
						$update['WAIT_ANSWER'] = 'N';
						$update['WAIT_VOTE'] = 'Y';
						$waitAction = true;
					}

					if ($waitAction !== true)
					{
						if ($auto)
						{
							$params = [
								'CLASS' => 'bx-messenger-content-item-ol-end'
							];
							if ($this->config['VOTE_MESSAGE'] === 'Y')
							{
								$params[Params::TYPE] = 'lines';
								$params[Params::COMPONENT_ID] = 'bx-imopenlines-message';
								$params[MessageParameter::IMOL_VOTE_SID] = $this->session['ID'];
								$params[MessageParameter::IMOL_VOTE_USER] = $this->session['VOTE'];
								$params[MessageParameter::IMOL_VOTE_HEAD] = $this->session['VOTE_HEAD'];
								$params[MessageParameter::IMOL_COMMENT_HEAD] = htmlspecialcharsbx($this->session['COMMENT_HEAD']);
							}
							$messages[] = [
								'TO_CHAT_ID' => $this->session['CHAT_ID'],
								'FROM_USER_ID' => $this->session['OPERATOR_ID'],
								'RECENT_ADD' => $userViewChat? 'Y': 'N',
								'MESSAGE' => Loc::getMessage('IMOL_SESSION_CLOSE_AUTO'),
								'SYSTEM' => 'Y',
								'PARAMS' => $params
							];
						}
						else
						{
							$userSkip = User::getInstance($this->chat->getData('OPERATOR_ID'));

							$params = [
								Params::STYLE_CLASS => 'bx-messenger-content-item-ol-end'
							];
							if ($this->config['VOTE_MESSAGE'] === 'Y')
							{
								$params[MessageParameter::IMOL_VOTE_SID] = $this->session['ID'];
								$params[MessageParameter::IMOL_VOTE_USER] = $this->session['VOTE'];
								$params[MessageParameter::IMOL_VOTE_HEAD] = $this->session['VOTE_HEAD'];
								$params[MessageParameter::IMOL_COMMENT_HEAD] = htmlspecialcharsbx($this->session['COMMENT_HEAD']);
							}
							$messages[] = [
								'TO_CHAT_ID' => $this->session['CHAT_ID'],
								'FROM_USER_ID' => $this->session['OPERATOR_ID'],
								'RECENT_ADD' => $userViewChat? 'Y': 'N',
								'MESSAGE' => Loc::getMessage(
									'IMOL_SESSION_CLOSE_' . $userSkip->getGender(),
									['#USER#' => '[USER=' . $userSkip->getId().'][/USER]']
								),
								'SYSTEM' => 'Y',
								'PARAMS' => $params
							];
						}

						$update['WAIT_ACTION'] = 'N';
						$update['WAIT_ANSWER'] = 'N';
						$update['CLOSED'] = 'Y';
					}

					if (!$auto)
					{
						if (!UserV2::getInstance((int)$this->session['OPERATOR_ID'])->isBot())
						{
							$update['DATE_OPERATOR_CLOSE'] = $currentDate;
						}
						if ($this->session['DATE_CREATE'] instanceof DateTime)
						{
							$update['TIME_CLOSE'] = $currentDate->getTimestamp() - $this->session['DATE_CREATE']->getTimestamp();
						}
					}
				}

				$update['DATE_MODIFY'] = $currentDate;
			}

			if (
				isset($update['CLOSED'])
				&& $update['CLOSED'] === 'Y'
			)
			{
				if ($this->session['CRM_ACTIVITY_ID'] > 0)
				{
					$crmManager = $this->getCrmManager();
					if ($crmManager->isLoaded())
					{
						$crmManager->setSessionClosed(['DATE_CLOSE' => $currentDate]);
					}
				}

				$update['DATE_MODIFY'] = $currentDate;
				$update['DATE_CLOSE'] = $currentDate;
				if (
					$this->session['TIME_CLOSE'] <= 0
					&& $this->session['DATE_CREATE'] instanceof DateTime
				)
				{
					$update['TIME_CLOSE'] = $update['DATE_CLOSE']->getTimestamp() - $this->session['DATE_CREATE']->getTimestamp();
				}
				if (
					$this->session['DATE_CREATE'] instanceof DateTime
					&& $this->session['TIME_BOT'] <= 0
					&& UserV2::getInstance((int)$this->session['OPERATOR_ID'])->isBot()
				)
				{
					$update['TIME_BOT'] = $update['DATE_CLOSE']->getTimestamp() - $this->session['DATE_CREATE']->getTimestamp();
				}
			}

			$this->update($update);

			// Reset the personal hidden messages mode for all operators of the chat on session finish.
			SilentMode\PersonalSilentMode::resetByChat((int)$this->session['CHAT_ID']);

			// send messages
			foreach ($messages as $message)
			{
				$addMessageId = Im::addMessage($message);

				if (!empty($addMessageId))
				{
					$lastMessageId = $addMessageId;
				}
			}

			if (
				isset($update['CLOSED'])
				&& $update['CLOSED'] === 'Y'
				&& $this->session['CHAT_ID']
			)
			{
				$this->update([
					'END_ID' => $lastMessageId
				]);
			}

			// Publishing once relies on $wasClosed in memory: parallel close paths are not serialized here.
			// A spam close is dropped before the snapshot is read: markSpam() saves the flag ahead of
			// finish(), so reading the row only to drop the event is a wasted query on every session of a
			// group spam action. The reason based check inside the integration stays as it is.
			if (
				isset($update['CLOSED'])
				&& $update['CLOSED'] === 'Y'
				&& $wasClosed === 'N'
				&& $force === false
				&& $this->session['SPAM'] !== 'Y'
				&& (int)$this->session['CRM_ACTIVITY_ID'] > 0
			)
			{
				$this->getCrmManager()->executeAutomationCloseTrigger((int)$this->session['ID']);
			}

			if ($hideChat)
			{
				Im::chatHide($this->session['CHAT_ID']);

				$fakeRelation = new Relation((int)$this->session['CHAT_ID']);
				$fakeRelation->removeAllRelations(true);
			}

			if (!empty($this->user['USER_CODE']))
			{
				$chatEntityId =	\Bitrix\ImOpenLines\Chat::parseLinesChatEntityId($this->user['USER_CODE']);
				if (!empty($chatEntityId['connectorId']) && !empty($chatEntityId['connectorChatId']))
				{
					ImConnector\Chat::deleteLastMessage($chatEntityId['connectorChatId'], $chatEntityId['connectorId']);
				}
			}

			if (
				isset($update['CLOSED'])
				&& $update['CLOSED'] === 'Y'
			)
			{
				$eventData = [];
				$eventData['RUNTIME_SESSION'] = $this;
				$eventData['SESSION'] = $this->session;
				$eventData['CONFIG'] = $this->config;
				$event = new Main\Event('imopenlines', 'OnSessionFinish', $eventData);
				$event->send();
			}

			$result = true;
		}

		return $result;
	}

	/**
	 * @return bool
	 */
	public function dismissedOperatorFinish()
	{
		if (empty($this->session))
		{
			return false;
		}

		$update = [];

		$this->session['CLOSED'] = 'Y';
		$update['FORCE_CLOSE'] = 'Y';

		if (!empty($this->session['DATE_LAST_MESSAGE']))
		{
			$closeDate = $this->session['DATE_LAST_MESSAGE']->add('60 MINUTES');
		}
		else
		{
			$closeDate = new DateTime();
		}

		if ($this->session['CHAT_ID'])
		{
			$chatData = ChatTable::getById($this->session['CHAT_ID'])->fetch();
			$lastMessageId = $chatData['LAST_MESSAGE_ID'];
		}
		else
		{
			$lastMessageId = 0;
		}

		$update['WAIT_ACTION'] = 'N';
		$update['WAIT_ANSWER'] = 'N';
		$update['WAIT_VOTE'] = 'N';
		$update['CLOSED'] = 'Y';

		if (!(
			$this->session['CLOSED'] == 'Y'
			|| $this->session['SPAM'] == 'Y'
			|| $this->session['WAIT_ACTION'] == 'Y' && $this->session['WAIT_ANSWER'] == 'N'
		))
		{
			if ($this->config['ACTIVE'] != 'N')
			{
				if (!UserV2::getInstance((int)$this->session['OPERATOR_ID'])->isBot())
				{
					$update['DATE_OPERATOR_CLOSE'] = $closeDate;
				}
				if ($this->session['DATE_CREATE'])
				{
					$update['TIME_CLOSE'] = $closeDate->getTimestamp()-$this->session['DATE_CREATE']->getTimestamp();
				}
			}
			$update['DATE_MODIFY'] = $closeDate;
		}

		if ($this->session['CRM_ACTIVITY_ID'] > 0)
		{
			$crmManager = $this->getCrmManager();
			if ($crmManager->isLoaded())
			{
				$crmManager->setSessionClosed(['DATE_CLOSE' => $closeDate]);
			}
		}

		$update['DATE_CLOSE'] = $closeDate;

		if ($this->session['TIME_CLOSE'] <= 0 && $this->session['DATE_CREATE'])
		{
			$update['TIME_CLOSE'] = $update['DATE_CLOSE']->getTimestamp()-$this->session['DATE_CREATE']->getTimestamp();
		}
		if (UserV2::getInstance((int)$this->session['OPERATOR_ID'])->isBot() && $this->session['TIME_BOT'] <= 0 && $this->session['DATE_CREATE'])
		{
			$update['TIME_BOT'] = $update['DATE_CLOSE']->getTimestamp()-$this->session['DATE_CREATE']->getTimestamp();
		}

		if ($this->session['CHAT_ID'])
		{
			$update['END_ID'] = $lastMessageId;
		}

		Im::chatHide($this->session['CHAT_ID']);

		//The data in the chat is not specially updated in order to avoid any notifications.
		$this->update($update);

		Debug::addSession($this,  __METHOD__, ['update' => $update]);
		$this->addEventToLog(Library::EVENT_SESSION_DISMISSED_OPERATOR_FINISH, $update);

		return true;
	}

	public function getData($field = '')
	{
		if ($field)
		{
			return isset($this->session[$field])? $this->session[$field]: null;
		}
		else
		{
			return $this->session;
		}
	}

	public function getConfig($field = '')
	{
		if ($field)
		{
			return isset($this->config[$field])? $this->config[$field]: null;
		}
		else
		{
			return $this->config;
		}
	}

	public function getUser($field = '')
	{
		if ($field)
		{
			return isset($this->user[$field])? $this->user[$field]: null;
		}
		else
		{
			return $this->user;
		}
	}

	/**
	 * @param $id
	 * @param bool $waitAnswer
	 * @param bool $autoMode
	 * @return bool
	 */
	public function setOperatorId($id, $waitAnswer = false, $autoMode = false)
	{
		$this->update([
			'WAIT_ANSWER' => $waitAnswer? 'Y': 'N',
			'OPERATOR_ID' => $id
		]);

		Debug::addSession($this,  __METHOD__, ['id' => $id, 'waitAnswer' => $waitAnswer, 'autoMode' => $autoMode]);

		return true;
	}

	/**
	 * Session update.
	 *
	 * TODO: the DATE_MODIFY field serves as a trigger for automatic actions!
	 * TODO: Required express refactor the method and change the approach.
	 * TODO: Hack. Manually changing the session status @see \Bitrix\ImOpenLines\Queue\All::transferToNext imopenlines/lib/queue/all.php:211
	 *
	 * @param $fields
	 * @return bool
	 */
	public function update($fields)
	{
		$update = new Update($this);
		$update->setData($fields);

		return $update->save();
	}

	/**
	 * @param $params
	 */
	public function execAutoAction($params): void
	{
		if (
			$this->action === self::ACTION_CLOSED
			&& $this->config['ACTIVE'] === 'N'
		)
		{
			Im::addMessage([
				'TO_CHAT_ID' => $this->session['CHAT_ID'],
				'MESSAGE' => Loc::getMessage('IMOL_SESSION_LINE_IS_CLOSED'),
				'SYSTEM' => 'Y',
			]);
		}

		if (
			$this->config['AGREEMENT_MESSAGE'] === 'Y'
			&& !$this->isAgreementWarningShownNow
			&& $this->isAgreementWarningPending()
			&& $this->isEnableSendSystemMessage()
		)
		{
			if ($this->isAgreementShownByWidgetConsentForm())
			{
				$this->rememberAgreementWarningShown();
			}
			elseif ($this->sendAgreementWarning())
			{
				$this->rememberAgreementWarningShown();
				$this->isAgreementWarningShownNow = true;
			}
		}
	}

	/**
	 * The widget shows the agreement text in its own consent form, so a visitor who has consented
	 * there was warned before this request even started.
	 */
	private function isAgreementShownByWidgetConsentForm(): bool
	{
		if (!Connector::isLiveChat($this->session['SOURCE']))
		{
			return false;
		}

		$agreementId = (int)($this->config['AGREEMENT_ID'] ?? 0);
		$parsedUserCode = Session\Common::parseUserCode($this->session['USER_CODE']);
		$externalChatId = (int)($parsedUserCode['EXTERNAL_CHAT_ID'] ?? 0);

		// without a chat id the lookup matches a consent of any other visitor of the same agreement
		if ($agreementId <= 0 || $externalChatId <= 0)
		{
			return false;
		}

		return (bool)Consent::getByContext($agreementId, 'imopenlines/livechat', $externalChatId);
	}

	/**
	 * @return bool Whether the warning has reached the client. A rejected message leaves the dialog
	 * unwarned so that the next request retries instead of registering a consent to an unseen text.
	 */
	private function sendAgreementWarning(): bool
	{
		$agreementLink = Common::getAgreementLink($this->config['AGREEMENT_ID'], $this->config['LANGUAGE_ID']);
		if ($agreementLink === '')
		{
			return false;
		}

		$mess = Loc::loadLanguageFile(__FILE__, $this->config['LANGUAGE_ID']);
		$agreementMessageId = Im::addMessage([
			'TO_CHAT_ID' => $this->session['CHAT_ID'],
			'MESSAGE' => str_replace(
				['#LINK_START#', '#LINK_END#'],
				['[URL=' . $agreementLink . ']', '[/URL]'],
				$mess['IMOL_SESSION_AGREEMENT_MESSAGE']
			),
			'SYSTEM' => 'Y',
			'IMPORTANT_CONNECTOR' => 'Y',
			'NO_SESSION_OL' => 'Y',
			'PARAMS' => [
				'CLASS'=> 'bx-messenger-content-item-ol-output',
			]
		]);

		if (!$agreementMessageId)
		{
			return false;
		}

		Im::addMessage([
			'TO_CHAT_ID' => $this->session['CHAT_ID'],
			'MESSAGE' => Loc::getMessage('IMOL_SESSION_AGREEMENT_MESSAGE_OPERATOR'),
			'SYSTEM' => 'Y',
			'PARAMS' => [
				'CLASS' => 'bx-messenger-content-item-ol-attention',
			]
		]);

		return true;
	}

	/**
	 * The warning about personal data processing is still due for this dialog. The relation keeps the
	 * agreement it was shown, so a line switched to another agreement warns the client again. A
	 * relation created before the field appeared carries an empty value, and the consent it already
	 * holds is the only trace left of the previous behaviour: with the consent the dialog counts as
	 * warned, without it the warning is sent again. A dialog opened right now is covered by the same
	 * rule: its relation starts at 0 and never matches a usable agreement.
	 */
	private function isAgreementWarningPending(): bool
	{
		if (empty($this->user))
		{
			return false;
		}

		$agreementId = (int)($this->config['AGREEMENT_ID'] ?? 0);
		if ($agreementId <= 0)
		{
			// the line promises a warning it has no agreement for, so nothing has been shown yet
			return true;
		}

		$shownAgreementId = $this->user['SHOWN_AGREEMENT_ID'] ?? null;
		if ($shownAgreementId === null || $shownAgreementId === '')
		{
			return (string)($this->user['AGREES'] ?? '') === 'N';
		}

		return (int)$shownAgreementId !== $agreementId;
	}

	/**
	 * The warning reached the client in one of the previous requests. A warning presented right now
	 * does not open the consent registration: automatic actions run before it in the same request.
	 */
	public function isAgreementWarningShownEarlier(): bool
	{
		return !empty($this->user)
			&& !$this->isAgreementWarningPending()
			&& !$this->isAgreementWarningShownNow
		;
	}

	private function rememberAgreementWarningShown(): void
	{
		$agreementId = (int)($this->config['AGREEMENT_ID'] ?? 0);

		\Bitrix\Imopenlines\Model\UserRelationTable::update(
			$this->user['USER_CODE'],
			['SHOWN_AGREEMENT_ID' => $agreementId]
		);

		$this->user['SHOWN_AGREEMENT_ID'] = $agreementId;
	}

	/**
	 * Transfer session to the next operator in the queue.
	 *
	 * @param bool $manual
	 * @param EndBotSessionEventContext|null $endBotSessionEventContext
	 * @return bool
	 */
	public function transferToNextInQueue($manual = true, ?EndBotSessionEventContext $endBotSessionEventContext = null)
	{
		$result = false;

		Debug::addSession($this,  __METHOD__, ['manual' => $manual]);

		$queueManager = $this->getQueueManager();
		if ($queueManager)
		{
			$endBotSessionEventContext = $this->prepareBotEndSessionEventContext($endBotSessionEventContext);
			$result = $queueManager->transferToNext($manual);
		}

		if ($result && $endBotSessionEventContext !== null)
		{
			$this->sendBotEndSessionAnalyticsEvent($endBotSessionEventContext);
		}

		return $result;
	}

	/**
	 * Send notification about unavailability of the operator.
	 *
	 * @return bool
	 */
	public function sendMessageNoAnswer()
	{
		$result = false;

		if ($this->config['NO_ANSWER_RULE'] == Session::RULE_TEXT && $this->session['SEND_NO_ANSWER_TEXT'] != 'Y' && $this->isEnableSendSystemMessage())
		{
			Im::addMessage([
				"TO_CHAT_ID" => $this->session['CHAT_ID'],
				"MESSAGE" => $this->config['NO_ANSWER_TEXT'],
				"SYSTEM" => 'Y',
				"IMPORTANT_CONNECTOR" => 'Y',
				"NO_SESSION_OL" => 'Y',
				"PARAMS" => [
					"CLASS" => "bx-messenger-content-item-ol-output",
					"IMOL_FORM" => "offline",
					"TYPE" => "lines",
					"COMPONENT_ID" => "bx-imopenlines-message",
				]
			]);
		}

		if ($this->session['SEND_NO_ANSWER_TEXT'] != 'Y')
		{
			$this->update(['SEND_NO_ANSWER_TEXT' => 'Y', 'WAIT_ACTION' => 'Y']);
		}
		else
		{
			$this->update(['SEND_NO_ANSWER_TEXT' => 'Y']);
		}

		Debug::addSession($this,  __METHOD__, ['result' => $result]);

		return $result;
	}

	/**
	 * @param int $idTask
	 * @param int $idConfigTask
	 * @param array|bool $configTask
	 * @return Result
	 */
	public function sendAutomaticMessage($idTask, $idConfigTask, $configTask = []): Result
	{
		$result = new Result();

		if (empty($configTask))
		{
			$configTask = ConfigAutomaticMessagesTable::getByPrimary($idConfigTask)->fetch();
		}

		if (
			$this->session['CLOSED'] !== 'Y'
			&& $this->session['STATUS'] < self::STATUS_WAIT_CLIENT
			&& !empty($configTask)
			&& $configTask['ACTIVE'] === 'Y'
			&& Loader::includeModule('imconnector')
			&& $this->isEnableSendSystemMessage()
		)
		{
			$operatorKeyboard = new \Bitrix\Im\Bot\Keyboard();

			$connectorKeyboard = [];

			if (!empty($configTask['TEXT_BUTTON_CLOSE']))
			{
				$bottomColor = '#86AE1E';
				$textColor = '#fff';

				$operatorKeyboard->addButton([
					'TEXT' => $configTask['TEXT_BUTTON_CLOSE'],
					'ACTION' => 'SEND',
					'ACTION_VALUE' => $configTask['LONG_TEXT_BUTTON_CLOSE'],
					'BG_COLOR' => $bottomColor,
					'TEXT_COLOR' => $textColor,
					'DISPLAY' => 'LINE',
					'DISABLED' => 'Y'
				]);

				$connectorKeyboard[] = [
					'TEXT_BUTTON' => $configTask['TEXT_BUTTON_CLOSE'],
					'LONG_TEXT' => $configTask['LONG_TEXT_BUTTON_CLOSE'],
					'BOTTOM_COLOR' => $bottomColor,
					'TEXT_COLOR' => $textColor,
					'DISPLAY' => 'LINE',
					'COMMAND' => \Bitrix\ImConnector\InteractiveMessage\Input::COMMAND_SESSION,
					'COMMAND_PARAMS' => [
						'COMMAND' => \Bitrix\ImConnector\InteractiveMessage\Input::COMMAND_SESSION_CLOSE,
						'SESSION_ID' => $this->session['ID'],
						'CHAT_ID' => $this->session['CHAT_ID'],
						'TASK_ID' => $idTask,
						'CONFIG_TASK_ID' => $idConfigTask,
					],
				];
			}

			if (!empty($configTask['TEXT_BUTTON_CONTINUE']))
			{
				$bottomColor = '#EE322D';
				$textColor = '#fff';

				$operatorKeyboard->addButton([
					'TEXT' => $configTask['TEXT_BUTTON_CONTINUE'],
					'ACTION' => 'SEND',
					'ACTION_VALUE' => $configTask['LONG_TEXT_BUTTON_CONTINUE'],
					'BG_COLOR' => $bottomColor,
					'TEXT_COLOR' => $textColor,
					'DISPLAY' => 'LINE',
					'DISABLED' => 'Y'
				]);

				$connectorKeyboard[] = [
					'TEXT_BUTTON' => $configTask['TEXT_BUTTON_CONTINUE'],
					'LONG_TEXT' => $configTask['LONG_TEXT_BUTTON_CONTINUE'],
					'BOTTOM_COLOR' => $bottomColor,
					'TEXT_COLOR' => $textColor,
					'DISPLAY' => 'LINE',
					'COMMAND' => \Bitrix\ImConnector\InteractiveMessage\Input::COMMAND_SESSION,
					'COMMAND_PARAMS' => [
						'COMMAND' => \Bitrix\ImConnector\InteractiveMessage\Input::COMMAND_SESSION_CONTINUE,
						'SESSION_ID' => $this->session['ID'],
						'CHAT_ID' => $this->session['CHAT_ID'],
						'TASK_ID' => $idTask,
						'CONFIG_TASK_ID' => $idConfigTask,
					],
				];
			}

			if (!empty($configTask['TEXT_BUTTON_NEW']))
			{
				$bottomColor = '#0CA7D9';
				$textColor = '#fff';

				$operatorKeyboard->addButton([
					'TEXT' => $configTask['TEXT_BUTTON_NEW'],
					'ACTION' => 'SEND',
					'ACTION_VALUE' => $configTask['LONG_TEXT_BUTTON_NEW'],
					'BG_COLOR' => $bottomColor,
					'TEXT_COLOR' => $textColor,
					'DISPLAY' => 'LINE',
					'DISABLED' => 'Y'
				]);

				$connectorKeyboard[] = [
					'TEXT_BUTTON' => $configTask['TEXT_BUTTON_NEW'],
					'LONG_TEXT' => $configTask['LONG_TEXT_BUTTON_NEW'],
					'BOTTOM_COLOR' => $bottomColor,
					'TEXT_COLOR' => $textColor,
					'DISPLAY' => 'LINE',
					'COMMAND' => \Bitrix\ImConnector\InteractiveMessage\Input::COMMAND_SESSION,
					'COMMAND_PARAMS' => [
						'COMMAND' => \Bitrix\ImConnector\InteractiveMessage\Input::COMMAND_SESSION_NEW,
						'SESSION_ID' => $this->session['ID'],
						'CHAT_ID' => $this->session['CHAT_ID'],
						'TASK_ID' => $idTask,
						'CONFIG_TASK_ID' => $idConfigTask,
					],
				];
			}
			$chatEntityId = Chat::parseLinesChatEntityId($this->chat->getData('ENTITY_ID'));
			$connectorId = $chatEntityId['connectorId'] ?? '';
			InteractiveMessage\Output::getInstance($this->session['CHAT_ID'], ['connectorId' => $connectorId])->setKeyboardData($connectorKeyboard);

			Im::addMessage([
				'TO_CHAT_ID' => $this->session['CHAT_ID'],
				'MESSAGE' => $configTask['MESSAGE'],
				'SYSTEM' => 'Y',
				'IMPORTANT_CONNECTOR' => 'Y',
				'NO_SESSION_OL' => 'Y',
				'RECENT_ADD' => 'N',
				'PARAMS' => [
					'CLASS' => 'bx-messenger-content-item-ol-output',
					'IMOL_FORM' => 'offline',
					'TYPE' => 'lines',
					'COMPONENT_ID' => 'bx-imopenlines-form-offline',
					'NOTIFY' => 'N',
				],
				'KEYBOARD' => $operatorKeyboard,
			]);
		}

		$resultDelete = SessionAutomaticTasksTable::delete($idTask);
		if (!$resultDelete->isSuccess())
		{
			$result->addErrors($resultDelete->getErrors());
		}

		Debug::addSession($this,  __METHOD__, ['result' => $result]);

		return $result;
	}

	private static function prolongDueChatActivity($chatId)
	{
		$orm = Model\SessionTable::getList([
			'select' => [
				'ID',
				'CHECK_DATE_CLOSE' => 'CHECK.DATE_CLOSE'
			],
			'filter' => [
				'=CHAT_ID' => $chatId,
				'=CLOSED' => 'N'
			]
		]);

		if ($result = $orm->fetch())
		{
			$currentDate = new DateTime();
			if ($result['CHECK_DATE_CLOSE'] && $currentDate->getTimestamp()+600 > $result['CHECK_DATE_CLOSE']->getTimestamp())
			{
				$dateClose = $result['CHECK_DATE_CLOSE']->add('10 MINUTES');
				Model\SessionCheckTable::update($result['ID'], [
					'DATE_CLOSE' => $dateClose
				]);
			}
		}
	}

	public static function setQueueFlagCache($type = "")
	{
		if (!$type)
		{
			return false;
		}

		$app = Application::getInstance();
		$managedCache = $app->getManagedCache();
		$managedCache->clean("imol_queue_flag_".$type);
		$managedCache->read(86400*30, "imol_queue_flag_".$type);
		$managedCache->set("imol_queue_flag_".$type, true);

		return true;
	}

	public static function deleteQueueFlagCache($type = "")
	{
		$app = Application::getInstance();
		$managedCache = $app->getManagedCache();
		if ($type)
		{
			$managedCache->clean("imol_queue_flag_".$type);
		}
		else
		{
			$managedCache->clean("imol_queue_flag_".self::CACHE_CLOSE);
			$managedCache->clean("imol_queue_flag_".self::CACHE_QUEUE);
			$managedCache->clean("imol_queue_flag_".self::CACHE_INIT);
			$managedCache->clean("imol_queue_flag_".self::CACHE_MAIL);
			$managedCache->clean("imol_queue_flag_".self::CACHE_NO_ANSWER);
		}

		return true;
	}

	/**
	 * @param string $type
	 * @return bool
	 */
	public static function getQueueFlagCache($type = "")
	{
		if (!$type)
		{
			return false;
		}

		$app = Application::getInstance();
		$managedCache = $app->getManagedCache();
		if ($result = $managedCache->read(86400*30, "imol_queue_flag_".$type))
		{
			$result = $managedCache->get("imol_queue_flag_".$type) === false? false: true;
		}
		return $result;
	}

	/**
	 * @return Chat
	 */
	public function getChat()
	{
		return $this->chat;
	}

	/**
	 * @param Chat $chat
	 */
	public function setChat(Chat $chat): self
	{
		$this->chat = $chat;

		return $this;
	}

	public function getAction()
	{
		return $this->action;
	}

	/**
	 * Remove all signs of automatic actions.
	 *
	 * @return bool
	 */
	public function resetActionAll(): bool
	{
		$this->action = self::ACTION_NONE;

		return true;
	}

	/**
	 * To add users to the chat.
	 *
	 * @return int
	 */
	public function joinUser(bool $skipRelation = false, int $fakeRelation = 0, int $fakeMessageId = 0)
	{
		Debug::addSession($this,  __METHOD__, ['joinUserList' => $this->joinUserList]);

		$messageId = 0;
		if (!empty($this->joinUserList))
		{
			$operatorFromCrm = false;
			if ($this->isNowCreated())
			{
				$operatorFromCrm = $this->session['OPERATOR_FROM_CRM'] == 'Y' ? true : false;
			}
			$messageId = $this->chat->sendJoinMessage($this->joinUserList, $operatorFromCrm, $fakeRelation, (int)$this->session['ID'], $fakeMessageId);
			$this->chat->join($this->joinUserList, true, false, $skipRelation);
		}

		return $messageId;
	}

	public function isNowCreated()
	{
		return $this->isCreated;
	}

	/**
	 * @param $fields
	 * @return bool
	 */
	public function updateCrmFlags($fields)
	{
		$result = false;
		$updateFields = [];

		if (
			(isset($fields['CRM_CREATE_LEAD']) && $fields['CRM_CREATE_LEAD'] == 'Y') ||
			(isset($fields['CRM_CREATE_COMPANY']) && $fields['CRM_CREATE_COMPANY'] == 'Y') ||
			(isset($fields['CRM_CREATE_CONTACT']) && $fields['CRM_CREATE_CONTACT'] == 'Y') ||
			(isset($fields['CRM_CREATE_DEAL']) && $fields['CRM_CREATE_DEAL'] == 'Y') ||
			(isset($fields['CRM_ACTIVITY_ID']) && !empty($fields['CRM_ACTIVITY_ID']))
		)
		{
			$updateFields['CRM'] = 'Y';
			$updateFields['CRM_CREATE'] = 'Y';
		}
		elseif((isset($fields['CRM_ACTIVITY_ID']) && !empty($fields['CRM_ACTIVITY_ID'])))
		{
			$updateFields['CRM'] = 'Y';
		}
		else
		{
			if (isset($fields['CRM_CREATE']))
			{
				if ($fields['CRM_CREATE'] == 'Y')
				{
					$updateFields['CRM_CREATE'] = 'Y';
					$updateFields['CRM'] = 'Y';
				}
				else
				{
					$updateFields['CRM_CREATE'] = 'N';

					if (isset($fields['CRM']))
					{
						if ($fields['CRM'] == 'Y')
						{
							$updateFields['CRM'] = 'Y';
						}
						else
						{
							$updateFields['CRM'] = 'N';
						}
					}
				}
			}
		}

		if (isset($fields['CRM_CREATE_LEAD']))
		{
			if ($fields['CRM_CREATE_LEAD'] == 'Y')
			{
				$updateFields['CRM_CREATE_LEAD'] = 'Y';
			}
			else
			{
				$updateFields['CRM_CREATE_LEAD'] = 'N';
			}
		}

		if (isset($fields['CRM_CREATE_COMPANY']))
		{
			if ($fields['CRM_CREATE_COMPANY'] == 'Y')
			{
				$updateFields['CRM_CREATE_COMPANY'] = 'Y';
			}
			else
			{
				$updateFields['CRM_CREATE_COMPANY'] = 'N';
			}
		}

		if (isset($fields['CRM_CREATE_CONTACT']))
		{
			if ($fields['CRM_CREATE_CONTACT'] == 'Y')
			{
				$updateFields['CRM_CREATE_CONTACT'] = 'Y';
			}
			else
			{
				$updateFields['CRM_CREATE_CONTACT'] = 'N';
			}
		}

		if (isset($fields['CRM_CREATE_DEAL']))
		{
			if ($fields['CRM_CREATE_DEAL'] == 'Y')
			{
				$updateFields['CRM_CREATE_DEAL'] = 'Y';
			}
			else
			{
				$updateFields['CRM_CREATE_DEAL'] = 'N';
			}
		}

		if (isset($fields['CRM_ACTIVITY_ID']))
		{
			$updateFields['CRM_ACTIVITY_ID'] = $fields['CRM_ACTIVITY_ID'];
		}

		if (!empty($updateFields))
		{
			foreach ($updateFields as $cell=>$field)
			{
				if ($this->getData($cell) == $field)
				{
					unset($updateFields[$cell]);
				}
			}
		}

		if (!empty($updateFields))
		{
			$updateFields['SKIP_CHANGE_STATUS'] = true;
			$result = $this->update($updateFields);
		}

		Debug::addSession($this,  __METHOD__, ['updateFields' => $updateFields, 'fields' => $fields]);

		return $result;
	}

	/**
	 * The vote of the user via a special form.
	 *
	 * @param $sessionId
	 * @param $action
	 * @param $userId
	 * @return bool
	 */
	public static function voteAsUser($sessionId, $action, $userId = null): bool
	{
		$result = false;

		$finishSession = false;
		$voteWindow = null;

		$pool = Application::getInstance()->getConnectionPool();
		// Not from a lagging replica: a stale snapshot would send a repeated vote down the regular path
		// and finish the session a second time.
		$pool->useMasterOnly(true);
		try
		{
			$sessionData = Model\SessionTable::getByIdPerformance($sessionId)->fetch();
			if (!$sessionData)
			{
				return false;
			}

			$userId = (int)$userId;
			if (
				$userId > 0
				&& $sessionData['USER_ID'] != $userId
			)
			{
				return false;
			}

			$voteValue = $action === 'dislike'? 1: 5;

			// Decided before load(), which for a closed session would reopen it for real - exactly what a
			// correction must not do.
			if (self::canRewriteClientVote($sessionData, $voteWindow))
			{
				return self::rewriteClientVote($sessionData, $voteValue, $voteWindow);
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		$connection = Application::getConnection();
		$lockName = self::VOTE_LOCK_PREFIX . (int)$sessionData['ID'];
		$isLocked = false;
		$pool->useMasterOnly(true);

		try
		{
			// Taken before load(), which reopens a closed session waiting for its mark for real: a refusal
			// behind it would leave the reopen in place while the mark itself was never stored.
			$isLocked = self::lockVote($connection, $lockName);
			if (!$isLocked)
			{
				return false;
			}

			// The route is decided again on a row that can no longer change under us: load() does not find a
			// vote session whose WAIT_VOTE a concurrent first vote has already dropped, so a later vote would
			// be refused there instead of applied as a correction.
			$rewritten = self::applyClientVoteRewrite($sessionData, $voteValue);
			if ($rewritten !== null)
			{
				return $rewritten;
			}

			$session = new Session();

			// The master-only mode is dropped for load() alone: it is heavy and may well be served by
			// a replica. The lock is held across it either way.
			$pool->useMasterOnly(false);
			try
			{
				$resultLoadSession = $session->load([
					'USER_CODE' => $sessionData['USER_CODE'],
					'SKIP_CREATE' => 'Y',
					'DEFERRED_JOIN' => 'Y',
					'VOTE_SESSION' => 'Y'
				]);
			}
			finally
			{
				$pool->useMasterOnly(true);
			}

			if (!$resultLoadSession)
			{
				if ($session->isCloseVote())
				{
					Im::addCloseVoteMessage(
						$session->getData('CHAT_ID'),
						$session->getConfig('VOTE_TIME_LIMIT'),
						$session->getData('USER_LANG')
					);
				}

				return false;
			}

			Debug::addSession(
				$session,
				__METHOD__,
				['sessionId' => $sessionId, 'action' => $action, 'userId' => $userId]
			);

			if ($session->getData('ID') != $sessionId)
			{
				return false;
			}

			if (
				$session->getData('CLOSED') !== 'Y'
				|| $session->getData('VOTE') !== ''
			)
			{
				$updateSession['VOTE'] = $voteValue;
				$updateSession['WAIT_VOTE'] = 'N';

				if (
					$session->getData('WAIT_VOTE') === 'Y'
					&& $session->getConfig('VOTE_CLOSING_DELAY') === 'Y'
				)
				{
					$finishSession = true;
				}
				else
				{
					//TODO: hack!
					$updateSession['STATUS'] = self::STATUS_WAIT_CLIENT;
					$updateSession['DATE_MODIFY'] = new DateTime;
					$updateSession['USER_ID'] = $userId;
				}

				// Asked once more because load() may reopen the session, and nobody else reopens under the vote
				// lock: a mark that appeared meanwhile must not be closed a second time.
				$rewritten = self::applyClientVoteRewrite($sessionData, $voteValue);
				if ($rewritten === null)
				{
					$session->update($updateSession);

					self::sendClientVoteSideEffects($sessionData, $voteValue);
					$result = true;
				}
				else
				{
					$result = $rewritten;
					$finishSession = false;
				}
			}
		}
		finally
		{
			self::releaseVoteLock($connection, $pool, $lockName, $isLocked);
		}

		// finish() stays outside the critical section: it sends messages and calls
		// connector, pull and CRM, which may hold an external HTTP call for seconds.
		if ($finishSession === true)
		{
			// A correction may have landed in the gap between the unlock and finish().
			$closeMessageVote = self::alignSessionWithStoredVote($session, (int)$sessionData['ID'], $voteValue);

			$session->finish(true);

			self::syncCloseMessageWithStoredVote((int)$sessionData['ID'], $closeMessageVote);
		}

		return $result;
	}

	/**
	 * Whether the vote corrects a mark the client has already given. The state alone does not say that
	 * the session is still closing (see isClientVoteRewritable()), so an open vote window is what admits
	 * the correction; an expired one sends the vote to the regular path.
	 *
	 * @param array|null $voteWindow out: the window the routing was decided by, kept for the single
	 *                               publication that happens without the lock, see rewriteClientVote().
	 */
	private static function canRewriteClientVote(array $sessionData, ?array &$voteWindow = null): bool
	{
		$voteWindow = null;

		if (!self::isClientVoteRewritable($sessionData))
		{
			return false;
		}

		$window = self::findVoteRequestWindow((int)$sessionData['ID'], (int)($sessionData['CHAT_ID'] ?? 0));
		if (!self::isVoteWindowOpen($window))
		{
			return false;
		}

		$voteWindow = $window;

		return true;
	}

	/**
	 * The state the correction path expects from the session row: the wait flag dropped and a mark
	 * already stored. Only the vote write produces that pair - a reopen touches neither field - so it
	 * identifies a session whose client has already voted; one still waiting for its first vote goes the
	 * regular path.
	 *
	 * CLOSED is not part of it on purpose: finish() writes CLOSED='Y' after the vote lock is released, so
	 * a vote arriving in that gap must be a correction. The cost is that a session reopened by a new
	 * client message is correctable too while its window is open, its status left to the first vote.
	 */
	private static function isClientVoteRewritable(array $sessionFields): bool
	{
		return
			($sessionFields['WAIT_VOTE'] ?? '') !== 'Y'
			&& (int)($sessionFields['VOTE'] ?? 0) > 0
		;
	}

	/**
	 * Whether the caller came to cast the first vote: its pre-lock snapshot still showed the session
	 * waiting for a mark. Such a vote is admitted without an open window - the core admitted it
	 * milliseconds ago, and a connector or REST session may carry no vote form at all, which an unknown
	 * window would turn into a refusal.
	 */
	private static function cameForTheFirstVote(array $sessionData): bool
	{
		return ($sessionData['WAIT_VOTE'] ?? '') === 'Y';
	}

	/**
	 * The window is decided by the vote-request message, never by the current line config, so that
	 * dropping the time limit in the line settings cannot reopen an expired mark. The rule is the one of
	 * \CIMMessenger::LinesSessionVote(), which admits or refuses the vote before it reaches us:
	 * DATE_CREATE of the request plus IMOL_TIME_LIMIT_VOTE, where an explicit zero means unlimited. The
	 * precomputed IMOL_DATE_CLOSE_VOTE takes no part in it - it is built before the message is sent, so a
	 * delay would close our window earlier than the core closes its own. A missing, unreadable or
	 * negative limit is unknown data and closes the window.
	 */
	private static function isVoteWindowOpen(?array $window): bool
	{
		if ($window === null)
		{
			return false;
		}

		if (!is_numeric($window['TIME_LIMIT']))
		{
			return false;
		}

		$timeLimit = (int)$window['TIME_LIMIT'];
		if ($timeLimit < 0)
		{
			return false;
		}

		if ($timeLimit === 0)
		{
			return true;
		}

		if (!($window['DATE_CREATE'] instanceof DateTime))
		{
			return false;
		}

		return $window['DATE_CREATE']->getTimestamp() + $timeLimit >= time();
	}

	/**
	 * The window the vote request of the session was sent with. The request is identified by
	 * IMOL_VOTE_SID == sessionId together with IMOL_FORM == 'like' on the same message, hence the
	 * self-join: the close message of finish(true) carries the session id but no form, and the other
	 * forms carry no session id. Both params are written by server handlers only, so the pair binds the
	 * window to this session. A session reopened after a vote holds several requests - the latest one is
	 * the form the client is looking at.
	 *
	 * @return array{MESSAGE_ID: int, DATE_CREATE: DateTime|null, TIME_LIMIT: string|null}|null
	 */
	private static function findVoteRequestWindow(int $sessionId, int $chatId): ?array
	{
		if ($sessionId <= 0 || $chatId <= 0)
		{
			return null;
		}

		$voteRequest = MessageParamTable::getList([
			'select' => ['MESSAGE_ID', 'DATE_CREATE' => 'MESSAGE.DATE_CREATE'],
			'filter' => [
				'=PARAM_NAME' => MessageParameter::IMOL_VOTE_SID,
				'=PARAM_VALUE' => (string)$sessionId,
				'=MESSAGE.CHAT_ID' => $chatId,
				'=FORM.PARAM_VALUE' => 'like',
			],
			'runtime' => [
				new Main\ORM\Fields\Relations\Reference(
					'FORM',
					MessageParamTable::class,
					Main\ORM\Query\Join::on('this.MESSAGE_ID', 'ref.MESSAGE_ID')->where(
						'ref.PARAM_NAME',
						MessageParameter::IMOL_FORM
					),
					['join_type' => Main\ORM\Query\Join::TYPE_INNER]
				),
			],
			'order' => ['MESSAGE_ID' => 'DESC'],
			'limit' => 1,
		])->fetch();

		if (!$voteRequest)
		{
			return null;
		}

		$timeLimit = MessageParamTable::getList([
			'select' => ['PARAM_VALUE'],
			'filter' => [
				'=MESSAGE_ID' => (int)$voteRequest['MESSAGE_ID'],
				'=PARAM_NAME' => MessageParameter::IMOL_TIME_LIMIT_VOTE,
			],
			'limit' => 1,
		])->fetch();

		return [
			'MESSAGE_ID' => (int)$voteRequest['MESSAGE_ID'],
			'DATE_CREATE' => $voteRequest['DATE_CREATE'],
			'TIME_LIMIT' => $timeLimit === false? null: $timeLimit['PARAM_VALUE'],
		];
	}

	/**
	 * A single bounded wait for the vote lock - see VOTE_LOCK_TIMEOUT for why it is deliberately short
	 * and why it is not repeated. Serialisation is mandatory here, not an optimisation: without it a
	 * request cannot tell a concurrent first vote from its own and would have to guess whether the session
	 * still has to be closed, and the stored mark could differ from the announced one. A vote that cannot
	 * be serialised is refused, and the refusal reaches the client as a REST error.
	 *
	 * The lock serialises voteAsUser(), not every write of SESSION.VOTE: a mark typed as text in an
	 * external channel is written by Connector::onReceivedMessage() without it.
	 */
	private static function lockVote(Main\DB\Connection $connection, string $lockName): bool
	{
		return $connection->lock($lockName, self::VOTE_LOCK_TIMEOUT);
	}

	/**
	 * Releases the vote lock and only then the master-only mode. The order is not cosmetic: on
	 * PostgreSQL unlock() is a query of its own (SELECT bx_release_lock(...)), and with master-only
	 * already dropped it may be routed to a replica - the session-level advisory lock would stay
	 * held on the master connection and every later vote on that session would wait for a lock
	 * nobody releases. Master-only is dropped in a finally of its own, so a throwing unlock() cannot
	 * leak the mode counter either.
	 */
	private static function releaseVoteLock(
		Main\DB\Connection $connection,
		Main\Data\ConnectionPool $pool,
		string $lockName,
		bool $isLocked
	): void
	{
		try
		{
			if ($isLocked)
			{
				$connection->unlock($lockName);
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	/**
	 * Takes the vote lock and the master-only mode for the correction, so that concurrent votes cannot
	 * store one mark and announce another, and delegates the write itself.
	 *
	 * @param array|null $voteWindow the window the routing was decided by, used for the refusal repaint
	 *                               below alone; the write reads a window of its own under the lock.
	 */
	private static function rewriteClientVote(array $sessionData, int $voteValue, ?array $voteWindow = null): bool
	{
		$connection = Application::getConnection();
		$pool = Application::getInstance()->getConnectionPool();
		$lockName = self::VOTE_LOCK_PREFIX . (int)$sessionData['ID'];

		$isLocked = false;
		$pool->useMasterOnly(true);

		try
		{
			$isLocked = self::lockVote($connection, $lockName);
			if (!$isLocked)
			{
				// Re-read rather than taken from the snapshot: the holder of the lock may have stored a mark of
				// its own, and the repaint must not undo one that has just been published.
				$storedState = self::readStoredVoteState((int)$sessionData['ID']);
				self::publishStoredVoteToForm(
					$voteWindow,
					(int)($storedState['VOTE'] ?? 0),
					self::isLiveChatSession($sessionData)
				);

				return false;
			}

			return self::applyClientVoteRewrite($sessionData, $voteValue) ?? false;
		}
		finally
		{
			self::releaseVoteLock($connection, $pool, $lockName, $isLocked);
		}
	}

	/**
	 * Overwrites the stored mark of an already voted session and announces the new one; the caller must
	 * hold the vote lock and the master-only mode. WAIT_VOTE and VOTE are re-read here and repeated in
	 * the UPDATE itself, see getClientVoteRewritableCondition(): the read decides the route, the
	 * condition keeps the write atomic. The window is read here too and never taken from the caller - it
	 * decides the admission, the counter and the message the mark is published to, and a reopen while the
	 * lock was waited for has already replaced the form the client sees.
	 *
	 * @return bool|null null when the session is not correctable and the caller must handle the vote.
	 */
	private static function applyClientVoteRewrite(array $sessionData, int $voteValue): ?bool
	{
		$sessionId = (int)$sessionData['ID'];
		$isLiveChatSession = self::isLiveChatSession($sessionData);
		$table = Model\SessionTable::getTableName();
		$connection = Application::getConnection();

		$currentFields = $connection->query(
			(new SqlExpression(
				'SELECT ?#, ?# FROM ?# WHERE ?# = ?i',
				'VOTE',
				'WAIT_VOTE',
				$table,
				'ID',
				$sessionId
			))->compile()
		)->fetch();

		if (!is_array($currentFields) || !self::isClientVoteRewritable($currentFields))
		{
			return null;
		}

		$currentVote = (int)$currentFields['VOTE'];

		$voteWindow = self::findVoteRequestWindow($sessionId, (int)($sessionData['CHAT_ID'] ?? 0));

		if (!self::cameForTheFirstVote($sessionData))
		{
			if (!self::isVoteWindowOpen($voteWindow))
			{
				// A refusal, including one of a mark equal to the stored one.
				self::publishStoredVoteToForm($voteWindow, $currentVote, $isLiveChatSession);

				return false;
			}
		}

		if ($currentVote === $voteValue)
		{
			// Accept idempotently, without repeating any side effect. An equal vote does not republish
			// either: that would reopen the load the change limit closes.
			return true;
		}

		$voteMessageId = (int)($voteWindow['MESSAGE_ID'] ?? 0);
		$changes = $voteMessageId > 0? self::readClientVoteChanges($voteMessageId): 0;

		if ($changes >= self::VOTE_CHANGE_LIMIT)
		{
			// Only corrections are limited: the first vote never reaches this branch and is never
			// refused for that reason. The client learns about the refusal from the REST error.
			self::publishStoredVoteToForm($voteWindow, $currentVote, $isLiveChatSession);

			return false;
		}

		// A guard on top of the lock, not a replacement: the compare-and-set keeps a repeated mark from
		// counting twice, and the state condition keeps the mark off a session that an ordinary client
		// request has reopened without the lock since the read above. The raw UPDATE bypasses the ORM
		// event, and nothing reads the session object after the vote within the same request.
		$writeSql = (new SqlExpression(
			'UPDATE ?# SET ?# = ?i WHERE ?# = ?i AND ?# <> ?i',
			$table,
			'VOTE',
			$voteValue,
			'ID',
			$sessionId,
			'VOTE',
			$voteValue
		))->compile()
			. ' AND ' . self::getClientVoteRewritableCondition()
		;

		$connection->queryExecute($writeSql);

		if ($connection->getAffectedRowsCount() <= 0)
		{
			return self::resolveUnwrittenClientVote($sessionId, $voteValue, $voteWindow, $isLiveChatSession);
		}

		self::countClientVoteChange($voteWindow, $changes);

		// Statistics expects the mark that is really being replaced, not the initial snapshot.
		$sessionData['VOTE'] = $currentVote;

		// END_ID is read after the write, as late as it can be: a concurrent finish() creates the close
		// message outside this lock, and syncCloseMessageWithStoredVote() looks for a divergence before it
		// takes the lock, so an END_ID read earlier could be 0 here while that check already saw the mark
		// this request is about to replace - and the close message would keep the replaced mark for good.
		$sessionData['END_ID'] = (int)(self::readStoredVoteState($sessionId)['END_ID'] ?? 0);

		// From inside the critical section, so that two concurrent votes cannot leave the form showing the
		// mark that lost the race.
		self::publishStoredVoteToForm($voteWindow, $voteValue, $isLiveChatSession);

		self::sendClientVoteSideEffects($sessionData, $voteValue);

		return true;
	}

	/**
	 * The SQL twin of isClientVoteRewritable(): the same conditions on the same fields, so that the
	 * write admits exactly the state the check admits. The two are not a duplication of one guard: the
	 * check decides which path handles the vote, the condition decides whether the row may still be
	 * written when the write actually happens.
	 */
	private static function getClientVoteRewritableCondition(): string
	{
		return (new SqlExpression(
			'?# > ?i AND ?# <> ?s',
			'VOTE',
			0,
			'WAIT_VOTE',
			'Y'
		))->compile();
	}

	/**
	 * Nothing was written, which the state condition of the UPDATE leaves ambiguous: either a concurrent
	 * request stored exactly this mark, or the session left the correctable state. The stored mark tells
	 * the two apart - equal means the client gets what it asked for, a different one is a refusal, and
	 * then the form goes back to the mark the session really holds.
	 */
	private static function resolveUnwrittenClientVote(
		int $sessionId,
		int $voteValue,
		?array $voteWindow,
		bool $isLiveChatSession
	): bool
	{
		$storedVote = (int)(self::readStoredVoteState($sessionId)['VOTE'] ?? 0);
		if ($storedVote === $voteValue)
		{
			return true;
		}

		self::publishStoredVoteToForm($voteWindow, $storedVote, $isLiveChatSession);

		return false;
	}

	/**
	 * How many mark changes the vote request has already been charged with. Meaningful only under the
	 * vote lock and on the master: read before the lock, the value is the same for every request of a
	 * concurrent wave, and the limit would bound the waves instead of the writes.
	 */
	private static function readClientVoteChanges(int $messageId): int
	{
		$changes = Application::getConnection()->queryScalar(
			(new SqlExpression(
				'SELECT ?# FROM ?# WHERE ?# = ?i AND ?# = ?s',
				'PARAM_VALUE',
				MessageParamTable::getTableName(),
				'MESSAGE_ID',
				$messageId,
				'PARAM_NAME',
				MessageParameter::IMOL_VOTE_CHANGES
			))->compile()
		);

		return (int)$changes;
	}

	/**
	 * Publishes the mark the session row really holds on the vote form the client is looking at:
	 * \CIMMessenger::LinesSessionVote() publishes the choice of the client before calling voteAsUser()
	 * and never looks at the answer, so a refusal or a lost race would leave the form ahead of the row.
	 * The twin message is written only for a livechat pair, as in the core (im_messenger.php:1344):
	 * elsewhere CONNECTOR_MID holds the id the provider gave, and a write by it would land on an
	 * unrelated message. The two are written together, so a request that is up to date has an up to
	 * date twin.
	 *
	 * @param bool $isLiveChatSession whether the vote request has a local twin, see isLiveChatSession()
	 */
	private static function publishStoredVoteToForm(
		?array $voteWindow,
		int $storedVote,
		bool $isLiveChatSession
	): void
	{
		$messageId = (int)($voteWindow['MESSAGE_ID'] ?? 0);
		if ($messageId <= 0 || $storedVote <= 0)
		{
			return;
		}

		$action = $storedVote === self::VOTE_LIKE ? 'like' : 'dislike';
		$messageParams = \CIMMessageParam::Get($messageId);

		$componentParams = $messageParams[Params::COMPONENT_PARAMS] ?? [];
		if (!is_array($componentParams))
		{
			$componentParams = [];
		}
		$hasComponentVote = array_key_exists(MessageParameter::IMOL_VOTE, $componentParams);
		$hasV2ComponentVote = array_key_exists(self::V2_COMPONENT_VOTE_PARAM, $componentParams);
		// Nothing to repaint when every UI generation already shows this mark: writing and pulling the
		// same value again would only cost queries and pull traffic.
		if (
			($messageParams[MessageParameter::IMOL_VOTE] ?? null) === $action
			&& (!$hasComponentVote || ($componentParams[MessageParameter::IMOL_VOTE] ?? null) === $action)
			&& (!$hasV2ComponentVote || ($componentParams[self::V2_COMPONENT_VOTE_PARAM] ?? null) === $action)
		)
		{
			return;
		}

		$componentParams[MessageParameter::IMOL_VOTE] = $action;
		if ($hasV2ComponentVote)
		{
			$componentParams[self::V2_COMPONENT_VOTE_PARAM] = $action;
		}

		\CIMMessageParam::Set($messageId, [
			MessageParameter::IMOL_VOTE => $action,
			Params::COMPONENT_PARAMS => $componentParams,
		]);
		\CIMMessageParam::SendPull($messageId, [MessageParameter::IMOL_VOTE, Params::COMPONENT_PARAMS]);

		if (!$isLiveChatSession)
		{
			return;
		}

		$twinMessageId = (int)($messageParams[MessageParameter::CONNECTOR_MID][0] ?? 0);
		if ($twinMessageId <= 0 || !self::isVoteRequestTwin($twinMessageId, $messageId))
		{
			return;
		}

		\CIMMessageParam::Set($twinMessageId, [MessageParameter::IMOL_VOTE => $action]);
		\CIMMessageParam::SendPull($twinMessageId, [MessageParameter::IMOL_VOTE]);
	}

	/**
	 * Whether the session mirrors its messages into a chat of this portal, so that CONNECTOR_MID on
	 * the vote request holds a local message id. Every other source is an external channel, where the
	 * same param holds the id the provider gave the message.
	 */
	private static function isLiveChatSession(array $sessionData): bool
	{
		return Connector::isLiveChat((string)($sessionData['SOURCE'] ?? ''));
	}

	/**
	 * Whether the message CONNECTOR_MID points at is really the twin of the vote request: the livechat
	 * pair links the two to each other (connector.php:1745), so a message that does not point back is
	 * not one. Guards against a number that only looks like a local message id.
	 */
	private static function isVoteRequestTwin(int $twinMessageId, int $messageId): bool
	{
		$twinParams = \CIMMessageParam::Get($twinMessageId);

		return (int)($twinParams[MessageParameter::CONNECTOR_MID][0] ?? 0) === $messageId;
	}

	/**
	 * Spends one of the allowed mark changes. Runs under the vote lock together with the accepted
	 * change, so the counter cannot fall behind the writes it limits. No SendPull: the counter is
	 * internal bookkeeping the client has no use for, and the vote path is exactly where extra pull
	 * traffic is not wanted.
	 */
	private static function countClientVoteChange(?array $voteWindow, int $changes): void
	{
		$messageId = (int)($voteWindow['MESSAGE_ID'] ?? 0);
		if ($messageId <= 0)
		{
			return;
		}

		\CIMMessageParam::Set($messageId, [MessageParameter::IMOL_VOTE_CHANGES => $changes + 1]);
	}

	/**
	 * Our own publications go first and the event last, because EventManager rethrows: a subscriber that
	 * throws takes down everything behind it. The order buys nothing for the handlers of the event
	 * itself - dispatching them is the core's queue, and our statistics handler (sort 500,
	 * install/index.php:206) still loses its turn to a third-party subscriber that throws with the
	 * default sort. VOTE is in the row before the event is sent either way.
	 */
	private static function sendClientVoteSideEffects(array $sessionData, int $voteValue): void
	{
		if ((int)($sessionData['END_ID'] ?? 0) > 0)
		{
			$voteParams = [
				MessageParameter::IMOL_VOTE_SID => (int)$sessionData['ID'],
				MessageParameter::IMOL_VOTE_USER => $voteValue,
			];
			\CIMMessageParam::Set((int)$sessionData['END_ID'], $voteParams);
			\CIMMessageParam::SendPull((int)$sessionData['END_ID'], array_keys($voteParams));
		}

		Chat::sendRatingNotify(
			Chat::RATING_TYPE_CLIENT,
			$sessionData['ID'],
			$voteValue,
			$sessionData['OPERATOR_ID'],
			$sessionData['USER_ID']
		);

		$event = new Main\Event('imopenlines', 'OnSessionVote', [
			'SESSION_DATA' => $sessionData,
			'VOTE' => $voteValue,
		]);

		// The mark is stored and announced by now, so a failing subscriber must not turn the vote into
		// a refusal for the client.
		try
		{
			$event->send();
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	/**
	 * Hands the mark the session row really holds to the session object that is about to close itself:
	 * finish() builds both the params of the close message and the payload of OnSessionFinish from its
	 * in-memory copy, while a correction that landed after the lock was released lives in the row only -
	 * and the message params are the only half syncCloseMessageWithStoredVote() can repair afterwards.
	 * Read from the master, like every other decision of the vote path.
	 *
	 * @return int the mark finish() is going to publish
	 */
	private static function alignSessionWithStoredVote(Session $session, int $sessionId, int $publishedVote): int
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$storedVote = (int)(self::readStoredVoteState($sessionId)['VOTE'] ?? 0);
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		if ($storedVote <= 0 || $storedVote === $publishedVote)
		{
			return $publishedVote;
		}

		$session->adoptStoredVote($storedVote);

		return $storedVote;
	}

	/**
	 * Takes the stored mark into the in-memory copy of the session, and only there: the value is
	 * already in the row, so an update() would repeat the ORM events and the status machine for a
	 * field that has not changed.
	 */
	private function adoptStoredVote(int $storedVote): void
	{
		$this->session['VOTE'] = $storedVote;
	}

	/**
	 * Republishes the params of the close message when a correction arrived while finish() was running:
	 * finish() builds them from its own in-memory copy, and the corrector could not fix them either,
	 * END_ID did not exist yet. OnSessionVote and the rating notify have already been sent by the
	 * corrector; an earlier correction belongs to alignSessionWithStoredVote(). The divergence is looked
	 * for by one read before anything is locked, so a line with VOTE_CLOSING_DELAY does not make every
	 * first vote wait for the lock only to find nothing to publish; the publication runs under the lock.
	 *
	 * @param int $publishedVote the mark the close message was built with
	 */
	private static function syncCloseMessageWithStoredVote(int $sessionId, int $publishedVote): void
	{
		$connection = Application::getConnection();
		$pool = Application::getInstance()->getConnectionPool();
		$lockName = self::VOTE_LOCK_PREFIX . $sessionId;

		$isLocked = false;
		$pool->useMasterOnly(true);

		try
		{
			if (!self::isCloseMessageBehind(self::readStoredVoteState($sessionId), $publishedVote))
			{
				return;
			}

			$isLocked = self::lockVote($connection, $lockName);
			if (!$isLocked)
			{
				return;
			}

			// Re-read under the lock: the mark may have changed once more since the cheap check, and
			// only a state read inside the critical section may be published from it.
			$storedState = self::readStoredVoteState($sessionId);
			if (!self::isCloseMessageBehind($storedState, $publishedVote))
			{
				return;
			}

			$voteParams = [
				MessageParameter::IMOL_VOTE_SID => $sessionId,
				MessageParameter::IMOL_VOTE_USER => $storedState['VOTE'],
			];
			\CIMMessageParam::Set($storedState['END_ID'], $voteParams);
			\CIMMessageParam::SendPull($storedState['END_ID'], array_keys($voteParams));
		}
		finally
		{
			self::releaseVoteLock($connection, $pool, $lockName, $isLocked);
		}
	}

	/**
	 * The stored mark of the session together with the close message it has to be shown on. Direct
	 * SQL and the master, like the rest of the vote path: the value decides what is published.
	 *
	 * @return array{VOTE: int, END_ID: int}|null null when the session row is gone
	 */
	private static function readStoredVoteState(int $sessionId): ?array
	{
		$storedFields = Application::getConnection()->query(
			(new SqlExpression(
				'SELECT ?#, ?# FROM ?# WHERE ?# = ?i',
				'VOTE',
				'END_ID',
				Model\SessionTable::getTableName(),
				'ID',
				$sessionId
			))->compile()
		)->fetch();

		if (!is_array($storedFields))
		{
			return null;
		}

		return [
			'VOTE' => (int)$storedFields['VOTE'],
			'END_ID' => (int)$storedFields['END_ID'],
		];
	}

	/**
	 * Whether the close message still shows another mark than the one the session holds: a
	 * correction has landed after this request had published its own.
	 */
	private static function isCloseMessageBehind(?array $storedState, int $publishedVote): bool
	{
		if ($storedState === null)
		{
			return false;
		}

		return
			$storedState['VOTE'] > 0
			&& $storedState['VOTE'] !== $publishedVote
			&& $storedState['END_ID'] > 0
		;
	}

	/**
	 * @param $sessionId
	 * @param null $voteValue
	 * @param null $commentValue
	 * @param null $userId
	 * @return bool
	 */
	public static function voteAsHead($sessionId, $voteValue = null, $commentValue = null, $userId = null)
	{
		$result = false;

		$sessionData = Model\SessionTable::getByIdPerformance($sessionId)->fetch();

		if ($sessionData)
		{
			$userId = (int)$userId;
			if (!$userId)
			{
				$userId = $GLOBALS['USER']->GetId();
			}

			$configManager = new \Bitrix\ImOpenLines\Config();
			$resultPermissions = $configManager->canVoteAsHead($sessionData['CONFIG_ID']);

			if ($resultPermissions)
			{
				$fieldsUpdate = [];

				$pullMessage = [
					'module_id' => 'imopenlines',
					'command' => 'voteHead',
					'expiry' => 60,
					'params' => [
						'sessionId' => $sessionId,
					],
				];

				$commentValueSafely = null;
				if ($commentValue !== null)
				{
					if (mb_strlen($commentValue) > 10000)
					{
						$commentValue = mb_substr($commentValue, 0, 10000).'...';
					}

					$commentValueSafely = htmlspecialcharsbx($commentValue);
				}

				if ($voteValue !== null && $commentValue !== null)
				{
					$voteValue = $voteValue == 1 || $voteValue <= 5? $voteValue: 0;

					$fieldsUpdate['VOTE_HEAD'] = $voteValue;

					$fieldsUpdate['COMMENT_HEAD'] = $commentValue;

					if ($voteValue > 0 && $commentValue !== '')
					{
						\Bitrix\ImOpenLines\Chat::sendRatingNotify(
							\Bitrix\ImOpenLines\Chat::RATING_TYPE_HEAD_AND_COMMENT,
							$sessionData['ID'],
							['vote' => $voteValue, 'comment' => $commentValue],
							$sessionData['OPERATOR_ID'],
							$userId
						);
					}
					elseif ($voteValue > 0)
					{
						\Bitrix\ImOpenLines\Chat::sendRatingNotify(
							\Bitrix\ImOpenLines\Chat::RATING_TYPE_HEAD,
							$sessionData['ID'],
							$voteValue,
							$sessionData['OPERATOR_ID'],
							$userId
						);
					}
					elseif ($commentValue !== '')
					{
						\Bitrix\ImOpenLines\Chat::sendRatingNotify(
							\Bitrix\ImOpenLines\Chat::RATING_TYPE_COMMENT,
							$sessionData['ID'],
							$commentValue,
							$sessionData['OPERATOR_ID'],
							$userId
						);
					}

					$result = true;
				}
				elseif($voteValue !== null)
				{
					$voteValue = $voteValue == 1 || $voteValue <= 5? $voteValue: 0;

					$fieldsUpdate['VOTE_HEAD'] = $voteValue;

					if ($voteValue > 0)
					{
						\Bitrix\ImOpenLines\Chat::sendRatingNotify(
							\Bitrix\ImOpenLines\Chat::RATING_TYPE_HEAD,
							$sessionData['ID'],
							$voteValue,
							$sessionData['OPERATOR_ID'],
							$userId
						);
					}

					$result = true;
				}
				elseif($commentValue !== null)
				{
					$fieldsUpdate['COMMENT_HEAD'] = $commentValue;

					if ($commentValue !== '')
					{
						\Bitrix\ImOpenLines\Chat::sendRatingNotify(
							\Bitrix\ImOpenLines\Chat::RATING_TYPE_COMMENT,
							$sessionData['ID'],
							$commentValue,
							$sessionData['OPERATOR_ID'],
							$userId
						);
					}

					$result = true;
				}

				if (!empty($fieldsUpdate))
				{
					Model\SessionTable::update($sessionId, $fieldsUpdate);
				}

				if (Loader::includeModule("pull") && ($voteValue !==null || $commentValue !==null))
				{
					$paramsPull = [
						'IMOL_VOTE_SID' => $sessionData['ID']
					];

					if ($voteValue !==null)
					{
						$pullMessage['params']['voteValue'] = $voteValue;
						$paramsPull['IMOL_VOTE_HEAD'] = $voteValue;
					}

					if ($commentValue !==null)
					{
						$pullMessage['params']['commentValue'] = $commentValueSafely;
						$paramsPull['IMOL_COMMENT_HEAD']['text'] = $commentValueSafely;
					}

					$pullUsers = \CIMChat::GetRelationById($sessionData['CHAT_ID'], false, true, false);
					$pullUsers[] = $userId;

					\Bitrix\Pull\Event::add($pullUsers, $pullMessage);

					if ($voteValue !==null)
					{
						$pullMessage['skip_users'] = $pullUsers;
						unset($pullMessage['params']['commentValue']);
						\CPullWatch::AddToStack('IMOL_STATISTICS', $pullMessage);
					}

					if ($sessionData['END_ID'] > 0)
					{
						\CIMMessageParam::Set($sessionData['END_ID'], $paramsPull);
						\CIMMessageParam::SendPull($sessionData['END_ID'], array_keys($paramsPull));
					}
				}
			}
		}

		return $result;
	}

	public static function setLastSendMailId(array $session, ?int $lastSendMailId = null): void
	{
		$chatId = (int)Chat::parseLinesChatEntityId($session['USER_CODE'])['connectorChatId'];
		$sessionId = (int)$session['ID'];
		$lastSendMailId ??= self::getLastMessageId($chatId);
		$sql = "
			UPDATE b_imopenlines_session 
			SET LAST_SEND_MAIL_ID = (case when LAST_SEND_MAIL_ID > {$lastSendMailId} then LAST_SEND_MAIL_ID else {$lastSendMailId} end)
			WHERE ID = {$sessionId}
		";
		Application::getConnection()->query($sql);
	}

	private static function getLastMessageId(int $chatId): int
	{
		Loader::includeModule('im');
		return (new ReadService())->getLastMessageIdInChat($chatId);
	}

	/**
	 * Closed sessions, which for some reason remained open in the presence of an actual and active session.
	 *
	 * @param $duplicateSession
	 * @param $actualSession
	 * @return Result
	 */
	protected static function closeDuplicate($duplicateSession, $actualSession)
	{
		$result = new Result();

		$resultSessionUpdate = Model\SessionTable::update($duplicateSession['ID'], [
			'STATUS' => self::STATUS_DUPLICATE,
			'WAIT_ANSWER' => 'N',
			'CLOSED' => 'Y'
		]);

		if (!$resultSessionUpdate->isSuccess())
		{
			$result->addErrors($resultSessionUpdate->getErrors());
		}

		$resultSessionCheckDelete = Model\SessionCheckTable::delete($duplicateSession['ID']);

		if (!$resultSessionCheckDelete->isSuccess())
		{
			$result->addErrors($resultSessionCheckDelete->getErrors());
		}

		if (
			$actualSession['CHAT_ID'] != $duplicateSession['CHAT_ID']
			|| $actualSession['OPERATOR_ID']
			&& $duplicateSession['OPERATOR_ID']
			&& $actualSession['OPERATOR_ID'] != $duplicateSession['OPERATOR_ID']
		)
		{
			$chatManager = new Chat($duplicateSession['CHAT_ID']);
			$chatManager->leave($duplicateSession['OPERATOR_ID']);
		}

		//statistics
		ConfigStatistic::getInstance((int)$duplicateSession['CONFIG_ID'])->addClosed()->deleteInWork();

		return $result;
	}

	/**
	 * Checks that message is first operator message in current session
	 *
	 * @param $messageId
	 * @return bool
	 */
	public function isFirstOperatorMessage($messageId)
	{
		$message = MessageTable::getList(
			[
				'select' => ['ID'],
				'order' => ['ID' => 'ASC'],
				'filter' => [
					'CHAT.ENTITY_ID' => $this->getData('USER_CODE'),
					'CHAT.ENTITY_TYPE' => 'LINES',
					'AUTHOR_ID' => $this->getData('OPERATOR_ID'),
					'>ID' => $this->getData('START_ID')
				]
			]
		)->fetch();

		return $message['ID'] == $messageId;
	}

	/**
	 * Add new event item to event log table.
	 *
	 * @param string $eventType
	 * @param Main\Result|array|null $result
	 *
	 * @return Main\ORM\Data\AddResult|Main\Result|mixed
	 */
	private function addEventToLog($eventType, $result = null)
	{
		if (is_null($result))
		{
			$result = new Result();
		}
		elseif (!($result instanceof Result))
		{
			$resultData = $result;
			$result = new Result();
			$result->setData($resultData);
		}

		$resultData = [];
		$fieldData = $result->getData();
		if (is_array($fieldData) && !empty($fieldData))
		{
			$resultData = array_merge($resultData, $fieldData);
		}
		$result->setData($resultData);
		$eventLog = EventLog::addEvent($eventType, $result, $this->config['ID'], $this->session['ID']);

		return $eventLog;
	}

	/**
	 * Check whether system messages can be sent to the session.
	 *
	 * @return bool
	 */
	public function isEnableSendSystemMessage(): bool
	{
		$result = false;

		if (
			$this->isDisabledSendSystemMessage === false
			&& !empty($this->connectorId)
			&& !ReplyBlock::isBlocked($this)
		)
		{
			$result = Connector::isEnableSendSystemMessage($this->connectorId);

			if (
				$result === true
				&& $this->action === self::ACTION_CLOSED
				&& $this->config['ACTIVE'] === 'N'
			)
			{
				$result = false;
			}
			elseif (Loader::includeModule('imconnector'))
			{
				$connectorHandler = ImConnector\Connector::initConnectorHandler($this->getData('SOURCE'));

				if (!empty($connectorHandler))
				{
					$result = $connectorHandler->isEnableSendSystemMessage($this);
				}
			}
		}

		return $result;
	}

	public function isCloseVote()
	{
		return $this->isCloseVote;
	}

	/**
	 * Forcibly disabling system messages.
	 *
	 * @param bool $value
	 */
	public function setDisabledSendSystemMessage(bool $value): void
	{
		$this->isDisabledSendSystemMessage = $value;
	}

	/**
	 * Forcibly disabling system messages.
	 *
	 * @param bool $value
	 */
	public function setForcedSendVote(bool $value): void
	{
		$this->isForcedSendVote = $value;
	}

	//Event
	public static function onSessionProlongLastMessage($chatId, $dialogId, $entityType = '', $entityId = '', $userId = '')
	{
		if ($entityType != 'LINES')
		{
			return true;
		}

		self::prolongDueChatActivity($chatId);

		return true;
	}

	public static function onSessionProlongWriting($params)
	{
		$entityType = $params['CHAT']['ENTITY_TYPE'] ?? null;
		if ($entityType !== 'LINES')
		{
			return true;
		}

		self::prolongDueChatActivity($params['CHAT']['ID']);

		return true;
	}

	public static function onSessionProlongChatRename($chatId, $title, $entityType = '', $entityId = '', $userId = '')
	{
		if ($entityType != 'LINES')
		{
			return true;
		}

		self::prolongDueChatActivity($chatId);

		return true;
	}

	//Agent
	/**
	 * @deprecated
	 *
	 * @param $nextExec
	 * @param $offset
	 * @return string
	 */
	public static function transferToNextInQueueAgent($nextExec, $offset = 0)
	{
		return Agent::transferToNextInQueue($nextExec, $offset);
	}

	/**
	 * @deprecated
	 *
	 * @param $nextExec
	 * @return string
	 */
	public static function closeByTimeAgent($nextExec)
	{
		return Agent::closeByTime($nextExec);
	}

	/**
	 * @deprecated
	 *
	 * @param $nextExec
	 * @return string
	 */
	public static function mailByTimeAgent($nextExec)
	{
		return Agent::mailByTime($nextExec);
	}

	/**
	 * @deprecated
	 *
	 * @return array
	 */
	public static function getAgreementFields()
	{
		return Session\Common::getAgreementFields();
	}

	/**
	 * Delete all work data about session with sessionId
	 *
	 * @param $sessionId
	 *
	 * @return bool
	 */
	public static function deleteSession($sessionId)
	{
		$sessionData = Model\SessionTable::getByIdPerformance($sessionId)->fetch();
		$chat = \Bitrix\Im\V2\Chat::getInstance($sessionData['CHAT_ID']);
		$chat->getRelations()->delete();

		Im::chatHide($sessionData['CHAT_ID']);
		$fakeRelation = new Relation((int)$sessionData['CHAT_ID']);
		$fakeRelation->removeAllRelations(true);

		SessionTable::delete($sessionId);
		SessionCheckTable::delete($sessionId);
		SessionIndexTable::delete($sessionId);
		$kpi = new KpiManager($sessionId);
		$kpi->deleteSessionMessages();
		unset($kpi);

		return true;
	}

	/**
	 * @deprecated
	 *
	 * @param $nextExec
	 * @return string
	 */
	public static function dismissedOperatorAgent($nextExec)
	{
		return '';
	}

	/**
	 * @return array
	 */
	public function getSession(): array
	{
		return $this->session;
	}

	/**
	 * @param string $fieldName
	 *
	 * @return mixed|null
	 */
	public function getSessionField(string $fieldName)
	{
		return $this->session[$fieldName] ?? null;
	}

	/**
	 * @param array $session
	 */
	public function setSessionField(string $fieldName, $value): void
	{
		$this->session[$fieldName] = $value;
	}

	public function getJoinUserList(): array
	{
		return $this->joinUserList;
	}

	public function getConfigLanguage(): ?string
	{
		if(!$language = $this->config['LANGUAGE_ID'])
		{
			$language = Loc::getCurrentLang();
		}

		return $language;
	}

	public function setLanguage(string $langCode): self
	{
		if (
			Loader::includeModule('intranet')
			&& in_array($langCode, array_keys(\Bitrix\Intranet\Util::getLanguageList()), true)
		)
		{
			$this->update(['USER_LANG' => $langCode]);
			$this->session['USER_LANG'] = $langCode;
		}

		return $this;
	}

	public function getLanguage(): ?string
	{
		return $this->session['USER_LANG'];
	}

	private function sendBotEndSessionAnalyticsEvent(?EndBotSessionEventContext $context = null): void
	{
		(new ImOpenLines\V2\Analytics\Bot\EndBotSessionEvent())
			->setContext($context)
			->send()
		;
	}

	private function prepareBotEndSessionEventContext(?EndBotSessionEventContext $context): ?EndBotSessionEventContext
	{
		if ($context === null)
		{
			return null;
		}

		$operator = User::getInstance((int)($this->session['OPERATOR_ID'] ?? null));
		if (
			($this->config['WELCOME_BOT_ENABLE'] ?? false) !== 'Y'
			|| !$operator->isBot()
		)
		{
			return null;
		}

		$botCode = $operator->getFields()['bot_data']['code'] ?? null;
		if (!is_string($botCode))
		{
			return null;
		}

		return (clone $context)
			->setBotCode($botCode)
		;
	}
}
