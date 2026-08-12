<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Forum\ForumTable;
use Bitrix\Mail\Helper\AnalyticsHelper;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Integration\Crm\Activity;
use Bitrix\Mail\Integration\Im\Chat;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

class Secretary extends Controller
{
	protected function processBeforeAction(Action $action)
	{
		if (! Loader::includeModule('intranet'))
		{
			return false;
		}
		return parent::processBeforeAction($action);
	}

	/**
	 * Configure actions.
	 *
	 * @return array
	 */
	public function configureActions()
	{
		$config = [
			'createChatFromMessage' => [
				'+prefilters' => [
					new \Bitrix\Main\Engine\ActionFilter\HttpMethod(['POST']),
					new \Bitrix\Main\Engine\ActionFilter\Csrf(),
				]
			],
			'onCalendarSave' => [
				'+prefilters' => [
					new \Bitrix\Main\Engine\ActionFilter\HttpMethod(['POST']),
					new \Bitrix\Main\Engine\ActionFilter\Csrf(),
				]
			],
			'getCalendarEventDataFromMessage' => [
				'+prefilters' => [
					new \Bitrix\Main\Engine\ActionFilter\HttpMethod(['POST']),
					new \Bitrix\Main\Engine\ActionFilter\Csrf(),
				]
			],
			'bindFeedPost' => [
				'+prefilters' => [
					new \Bitrix\Main\Engine\ActionFilter\HttpMethod(['POST']),
					new \Bitrix\Main\Engine\ActionFilter\Csrf(),
				]
			],
		];

		if (Loader::includeModule('intranet'))
		{
			$config['createChatFromMessage']['+prefilters'][] = new \Bitrix\Intranet\ActionFilter\IntranetUser();
			$config['onCalendarSave']['+prefilters'][] = new \Bitrix\Intranet\ActionFilter\IntranetUser();
			$config['getCalendarEventDataFromMessage']['+prefilters'][] = new \Bitrix\Intranet\ActionFilter\IntranetUser();
			$config['bindFeedPost']['+prefilters'][] = new \Bitrix\Intranet\ActionFilter\IntranetUser();
		}

		return $config;
	}

	/**
	 * Create chat for mail message or go back to existing chat.
	 *
	 * @param int $messageId mail message id
	 * @return int|null  chat id
	 * @throws \Bitrix\Main\LoaderException
	 * @throws SystemException
	 */
	public function createChatFromMessageAction(int $messageId): ?int
	{
		if (!Loader::includeModule('im'))
		{
			return null;
		}

		global $USER;
		$userId = $USER->GetID();

		if (!$this->canBindEntities($messageId, (int)$userId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED')));
			return null;
		}

		$message = \Bitrix\Mail\Integration\Intranet\Secretary::getMessage($messageId);
		$messageData = $message->toArray();
		$messageData['USER_IDS'] = [$USER->GetID()];

		if ($chatId = \Bitrix\Intranet\Secretary::getChatIdIfExists($messageId, 'MAIL'))
		{
			// get back to chat if user left in past
			if (! \Bitrix\Intranet\Secretary::isUserInChat($chatId, $userId))
			{
				\Bitrix\Intranet\Secretary::addUserToChat($chatId, $userId, false);
			}
		}
		else
		{
			$lockName = "chat_create_mail_{$messageId}";
			if (!Application::getConnection()->lock($lockName))
			{
				$this->addError(new Error(
						Loc::getMessage('MAIL_SECRETARY_CREATE_CHAT_LOCK_ERROR'), 'lock_error')
				);

				return null;
			}

			$createMailChatResult = Chat::createMailChat($messageData, $userId);

			if (!$createMailChatResult->isSuccess())
			{
				$this->addError($createMailChatResult->getError());

				return null;
			}

			$chatId = $createMailChatResult->getData()['chatId'];

			Application::getConnection()->unlock($lockName);
		}

		if (Loader::includeModule('pull'))
		{
			$mailboxId = \Bitrix\Mail\Integration\Intranet\Secretary::getMailboxIdForMessage($messageId);

			if($mailboxId)
			{
				\CPullWatch::addToStack(
					'mail_mailbox_' . $mailboxId,
					[
						'module_id' => 'mail',
						'command' => 'messageBindingCreated',
						'params' => [
							'messageId' => $messageId,
							'mailboxId' => $mailboxId,
							'entityType' => Message::ENTITY_TYPE_IM_CHAT,
							'entityId' => $chatId,
							'bindingEntityLink' =>
							\CComponentEngine::makePathFromTemplate(
								'/online/?IM_DIALOG=chat#chat_id#',
								[
									'chat_id' => $chatId,
								]
							),
						],
					]
				);
			}
		}

		return $chatId;
	}

	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function discussMessageInChatAction(
		string $dialogId,
		CurrentUser $user,
		int $messageId = 0,
		int $activityId = 0,
	): void
	{
		if (!Loader::includeModule('im'))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_MODULE_NOT_INSTALLED')));

			return;
		}

		$userId = (int)$user->getId();

		if ($activityId > 0)
		{
			$this->discussCrmActivityInChat($activityId, $dialogId, $userId);

			return;
		}

		if ($messageId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED')));

			return;
		}

		if (!$this->canBindEntities($messageId, $userId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED')));

			return;
		}

		$message = \Bitrix\Mail\Integration\Intranet\Secretary::getMessage($messageId);
		$messageData = $message->toArray();
		$messageData['USER_IDS'] = [$userId];

		$result = Chat::addMailInChat($messageData, $userId, $dialogId);

		if (!$result->isSuccess())
		{
			$this->addError($result->getError());
		}
	}

	private function discussCrmActivityInChat(int $activityId, string $dialogId, int $userId): void
	{
		if (!Loader::includeModule('crm'))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_MODULE_NOT_INSTALLED')));

			return;
		}

		$activity = \Bitrix\Crm\Service\Container::getInstance()->getActivityBroker()->getById($activityId);
		if (!$activity)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED')));

			return;
		}

		$provider = \CCrmActivity::GetActivityProvider($activity);
		if (!$provider || !$provider::checkReadPermission($activity, $userId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED')));

			return;
		}

		Email::uncompressActivityDescription($activity);

		$settings = is_string($activity['SETTINGS'] ?? null)
			? unserialize($activity['SETTINGS'], ['allowed_classes' => false])
			: ($activity['SETTINGS'] ?? []);
		$emailMeta = $settings['EMAIL_META'] ?? [];

		$from = $emailMeta['from'] ?? $emailMeta['__email'] ?? '';
		if (is_array($from))
		{
			$from = implode(', ', $from);
		}

		$to = $emailMeta['to'] ?? '';
		if (is_array($to))
		{
			$to = implode(', ', $to);
		}

		$bodyHtml = Email::getDescriptionHtmlByActivityFields($activity);
		$bodyForText = preg_replace('#<(style|script)[^>]*>.*?</\\1>#si', '', $bodyHtml);
		$bodyForText = str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $bodyForText);
		$body = html_entity_decode(strip_tags($bodyForText), ENT_QUOTES, 'UTF-8');

		$messageData = [
			'ID' => $activityId,
			'MAILBOX_ID' => 0,
			'SUBJECT' => $activity['SUBJECT'] ?? '',
			'BODY' => $body,
			'BODY_HTML' => $bodyHtml,
			'FIELD_FROM' => $from,
			'FIELD_TO' => $to,
			'FIELD_DATE' => $activity['START_TIME'] ?? '',
		];

		$message = \Bitrix\Mail\Item\Message::fromArray($messageData);

		$chatId = Chat::resolveDialogChatId($dialogId, $userId);
		if ($chatId === null)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED')));

			return;
		}

		$activityUrl = Activity::getShareableActivityUrl($activityId, $chatId);
		if ($activityUrl === null)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED')));

			return;
		}

		$result = Chat::postMailChatDiscussMessage($message, $dialogId, $userId, $activityUrl);

		if (!$result->isSuccess())
		{
			$this->addError($result->getError());
		}
	}

	public function onCalendarSaveAction(int $messageId, int $calendarEventId)
	{
		if ($this->provideAccessToMessage($messageId, $calendarEventId))
		{
			if ($this->postCalendarBackLinkComment($messageId, $calendarEventId))
			{
				$this->assignCreatedCalendarLabelToMessage($messageId, $calendarEventId);
			}
			else
			{
				$this->addError(new Error('secretary: comment post error'));
			}
		}
		else
		{
			$this->addError(new Error('secretary: grant access to message failed'));
		}
	}

	/**
	 * Bind an already published Live Feed post to a mail message.
	 *
	 * Access is gated the same way as the neighbouring bind actions — via canBindEntities()
	 * (mailbox ownership), so the acting user must own the message's mailbox.
	 *
	 * Sets the UF_MAIL_MESSAGE user field on the post directly through the user-field manager
	 * (not \CBlogPost::Update, which would rewrite unrelated post columns such as
	 * PREVIEW_TEXT_TYPE / ATTACH_IMG via CheckFields). This triggers
	 * \Bitrix\Mail\MessageUserType::onBeforeSave, which creates the
	 * Internals\MessageAccessTable(BLOG_POST) record and sends the messageBindingCreated pull
	 * command. The user field is non-multiple, so a repeated call with the same pair does not
	 * create duplicates (onBeforeSave clears older rows). The b_blog_post row — and the post
	 * author — is never touched.
	 *
	 * Fail-closed: onBeforeSave only writes the access record when the acting user owns the
	 * mailbox, so success is confirmed by the presence of the MessageAccessTable(BLOG_POST)
	 * record for this pair, not by the update result. On its absence the action logs and returns
	 * null with an error instead of reporting a false success.
	 *
	 * @param int $messageId mail message id
	 * @param int $postId Live Feed (blog) post id
	 * @return bool|null true on success; null on failure (errors are added to the controller).
	 */
	public function bindFeedPostAction(int $messageId, int $postId, CurrentUser $currentUser): ?bool
	{
		$userId = (int)$currentUser->getId();

		if (!$this->canBindEntities($messageId, $userId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_BIND_FEED_POST_NO_ACCESS'), 'ACCESS_DENIED'));

			return null;
		}

		if (!Loader::includeModule('blog'))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_MODULE_NOT_INSTALLED'), 'MODULE_NOT_INSTALLED'));

			return null;
		}

		if (!\CBlogPost::CanUserEditPost($postId, $userId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_BIND_FEED_POST_NO_EDIT_RIGHTS'), 'NO_POST_EDIT_RIGHTS'));

			return null;
		}

		// Write UF_MAIL_MESSAGE straight through the user-field manager. Going through
		// \CBlogPost::Update would run \CBlogPost::CheckFields, which fills defaults for unrelated
		// post columns (PREVIEW_TEXT_TYPE, ATTACH_IMG) by reference and rewrites them on the post.
		// A direct UF update never touches b_blog_post (the author is preserved without any
		// AUTHOR_ID juggling) and still fires MessageUserType::onBeforeSave. Passing $userId makes
		// the mailbox-ownership check inside onBeforeSave explicit instead of relying on global $USER.
		global $USER_FIELD_MANAGER;
		$USER_FIELD_MANAGER->Update('BLOG_POST', $postId, ['UF_MAIL_MESSAGE' => $messageId], $userId);

		if (!$this->isFeedPostBound($messageId, $postId))
		{
			AddMessage2Log(
				sprintf(
					'bindFeedPostAction: no MessageAccessTable(BLOG_POST) record after UF update, binding not created: messageId=%d, postId=%d, userId=%d',
					$messageId,
					$postId,
					$userId,
				),
				'mail',
				2,
				true,
			);

			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_BIND_FEED_POST_UPDATE_FAILED'), 'BIND_FEED_POST_FAILED'));

			return null;
		}

		return true;
	}

	/**
	 * Whether a MessageAccessTable(BLOG_POST) binding exists for the message-post pair.
	 *
	 * @param int $messageId mail message id
	 * @param int $postId Live Feed (blog) post id
	 * @return bool
	 */
	private function isFeedPostBound(int $messageId, int $postId): bool
	{
		$binding = MessageAccessTable::query()
			->where('MESSAGE_ID', $messageId)
			->where('ENTITY_TYPE', MessageAccessTable::ENTITY_TYPE_BLOG_POST)
			->where('ENTITY_ID', $postId)
			->setSelect(['TOKEN'])
			->setLimit(1)
			->fetch();

		return $binding !== false && $binding !== null;
	}

	/**
	 * Assign event label to mail messages list.
	 *
	 * @param int $messageId
	 * @param int $calendarEventId
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function assignCreatedCalendarLabelToMessage(int $messageId, int $calendarEventId): bool
	{
		if (Loader::includeModule('pull'))
		{
			$mailboxId = \Bitrix\Mail\Integration\Intranet\Secretary::getMailboxIdForMessage($messageId);

			if($mailboxId)
			{
				global $USER;

				$userPage = \Bitrix\Main\Config\Option::get('socialnetwork', 'user_page', '/company/personal/', SITE_ID);

				\CPullWatch::addToStack(
					'mail_mailbox_' . $mailboxId,
					[
						'module_id' => 'mail',
						'command' => 'messageBindingCreated',
						'params' => [
							'messageId' => $messageId,
							'mailboxId' => $mailboxId,
							'entityType' => Message::ENTITY_TYPE_CALENDAR_EVENT,
							'entityId' => $calendarEventId,
							'bindingEntityLink' =>
								\CComponentEngine::makePathFromTemplate(
									$userPage . 'user/#user_id#/calendar/?EVENT_ID=#event_id#',
									[
										'user_id' => $USER->getId(),
										'event_id' => $calendarEventId,
									]
								),
						],
					]
				);
			}

			return true;
		}

		return false;
	}

	/**
	 * Grant access to message for calendar event attendees.
	 *
	 * @param int $messageId
	 * @param int $calendarEventId
	 * @return bool
	 */
	private function provideAccessToMessage(int $messageId, int $calendarEventId): bool
	{
		global $USER;
		$userId = $USER->GetID();

		return \Bitrix\Mail\Integration\Intranet\Secretary::provideAccessToMessage(
			$messageId,
			Message::ENTITY_TYPE_CALENDAR_EVENT,
			$calendarEventId,
			$userId
		);
	}

	/**
	 * Post comment to calendar event with tokenized backlink.
	 *
	 * @param int $messageId
	 * @param int $calendarEventId
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 */
	private function postCalendarBackLinkComment(int $messageId, int $calendarEventId): bool
	{
		if (! Loader::includeModule('calendar'))
		{
			$this->addError(new Error('module calendar unloaded'));
			return false;
		}

		if (! Loader::includeModule('forum'))
		{
			$this->addError(new Error('module forum unloaded'));
			return false;
		}

		global $USER;
		$userId = (int)$USER->GetID();

		$xmlId = 'EVENT_' . $calendarEventId;

		$calendarEntry = \CCalendarEvent::getEventForViewInterface($calendarEventId, [
			'eventDate' => '',
			'userId' => $userId,
		]);

		if (!$this->canBindEntities($messageId, $userId))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED')));
			return false;
		}

		if (! isset($calendarEntry['CREATED_BY']) || (int)$calendarEntry['CREATED_BY'] !== $userId)
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED_CALENDAR')));
			return false;
		}

		if ($calendarEntry)
		{
			$xmlId = \CCalendarEvent::getEventCommentXmlId($calendarEntry);
		}

		$feedParams = [
			'type' => 'EV', // \Bitrix\Socialnetwork\Livefeed\ForumPost::getForumTypeMap()
			'id' => $calendarEventId,
			'xml_id' => $xmlId,
		];

		$forumId = self::getForumId(array_merge($feedParams, [
			'SITE_ID' => SITE_ID,
		]));

		if (!$forumId)
		{
			$this->addError(new Error('forum id error'));
			return false;
		}

		$feed = new \Bitrix\Forum\Comments\Feed(
			$forumId,
			$feedParams,
			$userId
		);

		$link = \Bitrix\Mail\Integration\Intranet\Secretary::getMessageUrlForCalendarEvent($messageId, $calendarEventId);
		$commentMessage = Loc::getMessage('MAIL_SECRETARY_POST_MESSAGE_CALENDAR_EVENT', [
			'#LINK#' => $link,
		]);

		$forumMessageFields = [
			'POST_MESSAGE' => $commentMessage,
		];
		$forumComment = $feed->add($forumMessageFields);

		if (!$forumComment)
		{
			$this->addError(new Error('forum comment error'));
			return false;
		}

		// TODO post to social network feed
		// if (! Loader::includeModule('socialnetwork'))
		// {
		// 	$this->addError(new Error('module socialnetwork unloaded'));
		// 	return null;
		// }

		return true;
	}

	/**
	 * Prepare name and description for new calendar event,
	 * created from mail message.
	 *
	 * @param int $messageId
	 * @return array|null
	 * @throws \Bitrix\Main\LoaderException
	 */
	public function getCalendarEventDataFromMessageAction(int $messageId)
	{
		if (! Loader::includeModule('intranet'))
		{
			$this->addError(new Error('module intranet unloaded')); // FIXME translate
			return null;
		}

		global $USER;
		if (!$this->canBindEntities($messageId, (int)$USER->getId()))
		{
			$this->addError(new Error(Loc::getMessage('MAIL_SECRETARY_ACCESS_DENIED')));
			return null;
		}

		$message = \Bitrix\Mail\Integration\Intranet\Secretary::getMessage($messageId);
		$address = new \Bitrix\Main\Mail\Address($message->getFrom());

		$link = \Bitrix\Mail\Integration\Intranet\Secretary::getDirectMessageUrl($message->getId());
		$link = AnalyticsHelper::addAnalyticsToMessage($link, ['source' => AnalyticsHelper::SOURCE_TYPE_EVENT]);

		$desc = Loc::getMessage('MAIL_SECRETARY_CALENDAR_EVENT_DESC', [
			'#SUBJECT#' => htmlspecialcharsbx($message->getSubject()),
			'#FROM#' => htmlspecialcharsbx($message->getFrom()),
			'#DATE#' => $message->getDate()->toString(),
			'#LINK_FROM#' => 'mailto:' . htmlspecialcharsbx($address->getEmail()),
			'#LINK#' => $link,
		]);

		$isIcal = Message::isIcalMessage($message);

		return [
			'name' => htmlspecialcharsbx($message->getSubject()),
			'desc' => $desc,
			'isIcal' => $isIcal,
			'isNewEvent' => !$isIcal,
			// 'userIds' => $data['USER_IDS'],
		];
	}

	// FIXME copypaste from forum module, must be simplified
	private static function getForumId($params = [])
	{
		$result = 0;

		$siteId = (
		isset($params['SITE_ID'])
		&& $params['SITE_ID'] <> ''
			? $params['SITE_ID']
			: SITE_ID
		);

		if (isset($params['type']))
		{
			if ($params['type'] === 'TK')
			{
				$result = Option::get('tasks', 'task_forum_id', 0, $siteId);

				if (
					(int)$result <= 0
					&& Loader::includeModule('forum')
				)
				{
					$res = ForumTable::getList([
						'filter' => [
							'=XML_ID' => 'intranet_tasks',
						],
						'select' => [ 'ID' ],
					]);
					if ($forumFields = $res->fetch())
					{
						$result = (int)$forumFields['ID'];
					}
				}
			}
			elseif ($params['type'] === 'WF')
			{
				$result = Option::get('bizproc', 'forum_id', 0, $siteId);

				if ((int)$result <= 0)
				{
					$res = ForumTable::getList([
						'filter' => [
							'=XML_ID' => 'bizproc_workflow',
						],
						'select' => [ 'ID' ],
					]);
					if ($forumFields = $res->fetch())
					{
						$result = (int)$forumFields['ID'];
					}
				}
			}
			elseif (in_array($params['type'], [ 'TM', 'TR' ]))
			{
				$result = Option::get('timeman', 'report_forum_id', 0, $siteId);
			}
			elseif (
				$params['type'] === 'EV'
				&& Loader::includeModule('calendar')
			)
			{
				$calendarSettings = \CCalendar::getSettings();
				$result = $calendarSettings["forum_id"];
			}
			elseif (
				$params['type'] === 'PH'
				&& Loader::includeModule('forum')
			)
			{
				$res = ForumTable::getList(array(
					'filter' => array(
						'=XML_ID' => 'PHOTOGALLERY_COMMENTS'
					),
					'select' => array('ID')
				));
				if ($forumFields = $res->fetch())
				{
					$result = (int)$forumFields['ID'];
				}
			}
			elseif ($params['type'] === 'IBLOCK')
			{
				$result = Option::get('wiki', 'socnet_forum_id', 0, $siteId);
			}
			else
			{
				$res = ForumTable::getList(array(
					'filter' => array(
						'=XML_ID' => 'USERS_AND_GROUPS'
					),
					'select' => array('ID')
				));
				if ($forumFields = $res->fetch())
				{
					$result = (int)$forumFields['ID'];
				}
			}
		}

		return $result;
	}

	/**
	 * Check user can create linked entities
	 *
	 * @param int $messageId
	 * @param int $userId
	 * @return bool
	 */
	private function canBindEntities(int $messageId, int $userId): bool
	{
		return  \Bitrix\Mail\MessageAccess::createByMessageId($messageId, $userId)->canModifyMessage();
	}
}
