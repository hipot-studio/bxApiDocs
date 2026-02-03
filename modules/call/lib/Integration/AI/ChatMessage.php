<?php

namespace Bitrix\Call\Integration\AI;

use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\Call\Call;
use Bitrix\Call\Library;
use Bitrix\Call\NotifyService;
use Bitrix\Call\CallChatMessage;
use Bitrix\Call\Integration\AI\Outcome\Insights;
use Bitrix\Call\Integration\AI\Outcome\Overview;
use Bitrix\Call\Integration\AI\Outcome\Evaluation;
use Bitrix\Call\Integration\AI\Outcome\OutcomeCollection;
use Bitrix\Call\Integration\Im\CallFollowupBot;


class ChatMessage extends CallChatMessage
{
	public const COPILOT_COLOR = '#8d51eb';

	public static function generateErrorMessage(\Bitrix\Main\Error $error, Chat $chat, Call $call): ?Message
	{
		$errorMessage = self::getErrorMessage($error, $chat) ?: $error->getMessage();

		$message = new Message();
		$message
			->setMessage($errorMessage)
			->markAsSystem(true);

		$message->getParams()->get(Params::COMPONENT_PARAMS)->setValue([
			'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AI_FAILED,
			'CALL_ID' => $call->getId(),
		]);

		return $message;
	}

	private static function getErrorMessage(\Bitrix\Main\Error $error, Chat $chat): string
	{
		$errorMessage = '';
		$errorCode = $error->getCode();

		if ($error instanceof CallAIError)
		{
			if ($error->isAiGeneratedError())
			{
				$customData = $error->getCustomData();
				if ($customData && !empty($customData['msgForIm']))
				{
					/** @see \Bitrix\AI\Engine::throwError */
					$errorMessage = $customData['msgForIm'];
				}
				else
				{
					$errorMessage = $error->getMessage();
				}
			}
			else
			{
				$errCodes = (new \ReflectionClass(CallAIError::class))->getConstants();
				if (in_array($errorCode, $errCodes, true))
				{
					$errorMessage = $error->getMessage();
				}
			}
		}

		switch ($errorCode)
		{
			case CallAIError::AI_MODULE_ERROR:
				$errorMessage = static::getMessage('ERROR_AI_MODULE_ERROR');
				break;

			case CallAIError::AI_UNAVAILABLE_ERROR:
			case 'AI_ENGINE_ERROR_SERVICE_IS_NOT_AVAILABLE_BY_TARIFF':
				$errorMessage = static::getMessage('CALL_NOTIFY_COPILOT_ERROR_TARIFF_RESTRICTION');
				break;

			case CallAIError::AI_SETTINGS_ERROR:
				$errorMessage = static::getMessage('CALL_NOTIFY_COPILOT_ERROR_SETTINGS_RESTRICTION', [
					'#LINK#' => CallAISettings::getHelpUrl(),
				]);
				break;

			case CallAIError::AI_NOT_ENOUGH_BAAS_ERROR:
			case 'AI_ENGINE_ERROR_LIMIT_IS_EXCEEDED':
				$errorMessage = static::getMessage('CALL_NOTIFY_COPILOT_ERROR_ERROR_LIMIT_BAAS', [
					'#LINK#' => CallAIBaasService::getBaasUrl(),
				]);
				break;

			case CallAIError::AI_AGREEMENT_ERROR:
			case 'AI_ENGINE_ERROR_MUST_AGREE_WITH_AGREEMENT':
				if (CallAISettings::isB24Mode())
				{
					//b24
					$users = $chat->getUserIds();
					$hasAdmin = false;
					foreach ($users as $userId)
					{
						if (\CBitrix24::isPortalAdmin($userId))
						{
							$hasAdmin = true;
							break;
						}
					}
					$errorMessage = $hasAdmin
						? static::getMessage('CALL_NOTIFY_COPILOT_ERROR_AGREEMENT_RESTRICTION_B24_ADMIN', ['#LINK#' => '/'])
						: static::getMessage('CALL_NOTIFY_COPILOT_ERROR_AGREEMENT_RESTRICTION_B24');
				}
				else
				{
					//box
					$errorMessage = static::getMessage('CALL_NOTIFY_COPILOT_ERROR_AGREEMENT_RESTRICTION_BOX', [
						'#LINK#' => CallAISettings::getAgreementUrl(),
					]);
				}
				break;
		}

		return $errorMessage;
	}

	public static function generateOverviewMessage(int $callId, OutcomeCollection $outcomeCollection, Chat $chat): ?Message
	{
		/** @var Overview $overview */
		$overview = $outcomeCollection->getOutcomeByType(SenseType::OVERVIEW->value)?->getSenseContent();
		if (!$overview)
		{
			return null;
		}
		/** @var Evaluation $evaluation */
		$evaluation = $outcomeCollection->getOutcomeByType(SenseType::EVALUATION->value)?->getSenseContent();

		$hostUrl = UrlManager::getInstance()->getHostUrl();

		$call = \Bitrix\Im\Call\Registry::getCallWithId($callId);

		$message = self::makeMessageWithCallLink('CALL_NOTIFY_TASK_COMPLETE', $callId, $chat);

		$attach = new \CIMMessageParamAttach();
		$attach->SetColor(self::COPILOT_COLOR);
		$delimiter = [/*'COLOR' => self::COPILOT_COLOR,*/ 'SIZE' => 400];
		$spacer = ['SIZE' => 1];

		if (!empty($overview->topic))
		{
			$attach->AddMessage('[b]'.$overview->topic.'[/b]');
		}

		$callUsers = $call->getCallUsers();
		$users = [];
		foreach ($callUsers as $userId => $callUser)
		{
			if ($callUser->getFirstJoined())
			{
				$userName = \Bitrix\Im\User::getInstance($userId)->getFullName();
				$users[$userId] = "[user={$userId}]{$userName}[/user]";
				if (count($users) > 20)
				{
					break;
				}
			}
		}
		if (!isset($users[$call->getInitiatorId()]))
		{
			$userId = $call->getInitiatorId();
			$userName = \Bitrix\Im\User::getInstance($userId)->getFullName();
			$users[$userId] = "[user={$userId}]{$userName}[/user]";
		}
		if ($users)
		{
			if (count($users) > 20)
			{
				$users[] = "...";
			}
			$attach->AddMessage(static::getMessage('CALL_NOTIFY_USERS', ['#USERS#' => implode(', ', $users)]));
		}

		$efficiencyValue = -1;
		if ($evaluation && $evaluation->efficiencyValue >= 0)
		{
			$efficiencyValue = $evaluation->efficiencyValue;
		}
		elseif ($overview->efficiencyValue >= 0)
		{
			$efficiencyValue = $overview->efficiencyValue;
		}
		if ($efficiencyValue >= 0)
		{
			$efficiency = sprintf(
				"%d%% (%s)",
				$efficiencyValue,
				match ($efficiencyValue)
				{
					100 => static::getMessage('CALL_NOTIFY_COPILOT_EFFICIENCY_100'),
					75 => static::getMessage('CALL_NOTIFY_COPILOT_EFFICIENCY_75'),
					50 => static::getMessage('CALL_NOTIFY_COPILOT_EFFICIENCY_50'),
					default => static::getMessage('CALL_NOTIFY_COPILOT_EFFICIENCY_25')
				}
			);
			$attach->AddMessage('[br][b]' . static::getMessage('CALL_NOTIFY_COPILOT_EFFICIENCY', ['#EFFICIENCY#' => $efficiency]) . '[/b]');
		}

		if ($overview?->agenda)
		{
			if ($overview->agenda?->explanation)
			{
				$attach->AddDelimiter($delimiter);
				$attach->AddUser([
					'NAME' => static::getMessage('CALL_NOTIFY_COPILOT_AGENDA'),
					'AVATAR' => $hostUrl.'/bitrix/js/call/images/copilot-message-agenda.svg',
				]);
				$attach->AddMessage($overview->agenda?->explanation);
			}
		}

		if ($overview?->agreements || $overview?->meetings || $overview?->tasks || $overview?->actionItems)
		{
			$attach->AddDelimiter($delimiter);
			$attach->AddUser([
				'NAME' => static::getMessage('CALL_NOTIFY_COPILOT_AGREEMENTS'),
				'AVATAR' => $hostUrl.'/bitrix/js/call/images/copilot-message-areements.svg',
			]);

			if (!empty($overview->agreements))
			{
				$attach->AddMessage('[b]' . static::getMessage('CALL_NOTIFY_COPILOT_AGREEMENTS_COMMON') . '[/b]');
				$number = 0;
				foreach ($overview->agreements as $agreement)
				{
					if (!empty($agreement->agreement))
					{
						$number++;
						$attach->AddMessage("{$number}. " . $agreement->agreement);
					}
				}
			}

			if (!empty($overview->actionItems))
			{
				$attach->AddMessage('[b]' . static::getMessage('CALL_NOTIFY_COPILOT_AGREEMENTS_TASKS') . '[/b]');
				$number = 0;
				foreach ($overview->actionItems as $action)
				{
					if (!empty($action->actionItem))
					{
						$number++;
						$attach->AddMessage("{$number}. " . $action->actionItem);
					}
				}
			}
			if (!empty($overview->tasks))
			{
				$attach->AddMessage('[b]' . static::getMessage('CALL_NOTIFY_COPILOT_AGREEMENTS_TASKS') . '[/b]');
				$number = 0;
				foreach ($overview->tasks as $task)
				{
					if (!empty($task->task))
					{
						$number++;
						$attach->AddMessage("{$number}. " . $task->task);
					}
				}
			}

			if (!empty($overview->meetings))
			{
				$attach->AddMessage('[b]' . static::getMessage('CALL_NOTIFY_COPILOT_AGREEMENTS_MEETINGS') . '[/b]');
				$number = 0;
				foreach ($overview->meetings as $meeting)
				{
					if (!empty($meeting->meeting))
					{
						$number++;
						$attach->AddMessage("{$number}. " . $meeting->meeting);
					}
				}
			}
		}

		/** @var Insights $insights */
		$insights = $outcomeCollection->getOutcomeByType(SenseType::INSIGHTS->value)?->getSenseContent();
		if ($insights)
		{
			if (!empty($insights->insights) || !empty($insights->speakerAnalysis))
			{
				$attach->AddDelimiter($delimiter);
				$attach->AddUser([
					'NAME' => static::getMessage('CALL_NOTIFY_COPILOT_INSIGHTS'),
					'AVATAR' => $hostUrl.'/bitrix/js/call/images/copilot-message-insights.svg',
				]);
				if ($insights->getVersion() > 1)
				{
					foreach ($insights->speakerAnalysis as $analysis)
					{
						if (!empty($analysis->detailedInsight))
						{
							$attach->AddMessage($analysis->detailedInsight . '[br][br]');
							$attach->AddDelimiter($spacer);
						}
					}
				}
				else
				{
					foreach ($insights->insights as $insight)
					{
						if (!empty($insight->detailedInsight))
						{
							$attach->AddMessage($insight->detailedInsight . '[br][br]');
							$attach->AddDelimiter($spacer);
						}
					}
				}
			}
		}

		$link = Library::getCallSliderUrl($callId);
		$attach->AddMessage('[br]'. static::getMessage('CALL_NOTIFY_COPILOT_DETAIL', ['#CALL_DETAIL#' => $link]). '[br]');

		$attach->AddDelimiter(['COLOR' => '#00ace3', 'SIZE' => 400]);
		$link = CallAISettings::getDisclaimerUrl();
		$attach->AddMessage('[i]'. static::getMessage('CALL_NOTIFY_COPILOT_DISCLAIMER', ['#DISCLAIMER#' => $link]). '[/i]');

		$message
			->setAttach($attach)
			->markAsSystem(true);

		$message->getParams()->get(Params::COMPONENT_PARAMS)->setValue([
			'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AI_OVERVIEW,
			'CALL_ID' => $callId,
		]);

		return $message;
	}

	public static function generateTaskCompleteMessage(Outcome $outcome, Chat $chat): ?Message
	{
		return self::makeMessageWithCallLink('CALL_NOTIFY_TASK_COMPLETE', $outcome->getCallId(), $chat);
	}

	public static function generateCallFinishedMessage(Call $call, Chat $chat): ?Message
	{
		$callId = $call->getId();
		$message = self::makeMessageWithCallLink('CALL_NOTIFY_TASK_START_V2', $callId, $chat);
		$message->getParams()->get(Params::COMPONENT_PARAMS)->setValue([
			'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AI_START,
			'CALL_ID' => $callId,
		]);

		return $message;
	}

	public static function generateWaitMessage(Call $call, Chat $chat): ?Message
	{
		$callId = $call->getId();
		$message = self::makeMessageWithCallLink('CALL_NOTIFY_TASK_WAIT', $callId, $chat);
		$message->getParams()->get(Params::COMPONENT_PARAMS)->setValue([
			'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AI_WAIT,
			'CALL_ID' => $callId,
		]);

		return $message;
	}

	public static function generateTaskStartMessage(int $callId, Chat $chat): ?Message
	{
		$message = self::makeMessageWithCallLink('CALL_NOTIFY_TASK_START_V2', $callId, $chat);
		$message->getParams()->get(Params::COMPONENT_PARAMS)->setValue([
			'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AI_START,
			'CALL_ID' => $callId,
		]);

		return $message;
	}

	public static function generateTaskFailedMessage(int $callId, \Bitrix\Main\Error $error, Chat $chat): ?Message
	{
		$mess = self::getPhraseWithCallLink('CALL_NOTIFY_TASK_FAILED', $callId, $chat);

		if ($errorMessage = self::getErrorMessage($error, $chat))
		{
			$mess .= '[br]'. $errorMessage;
		}

		$feedbackLink = \Bitrix\Call\Library::getCallAiFeedbackUrl($callId);
		if ($feedbackLink)
		{
			$mess .= '[br]'. static::getMessage('CALL_NOTIFY_FEEDBACK', ['#FEEDBACK_URL#' => $feedbackLink]);
		}

		$message = new Message();
		$message->markAsSystem(true);

		$message->getParams()->get(Params::COMPONENT_PARAMS)->setValue([
			'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AI_FAILED,
			'CALL_ID' => $callId,
		]);

		if (
			$error instanceof CallAIError
			&& $error->recoverable()
			&& ($errorMessage = static::getMessage('CALL_NOTIFY_COPILOT_CONTINUE'))
		)
		{
			$keyboard = new \Bitrix\Im\Bot\Keyboard(CallFollowupBot::getBotId());
			$button = [
				'COMMAND' => CallFollowupBot::COMMAND_CONTINUE_FOLLOWUP,
				'COMMAND_PARAMS' => 'CALL_ID:' . $callId,
				'TEXT' => $errorMessage,
			];
			$keyboard->addButton($button);
			$message->getParams()->get(Params::KEYBOARD)->setValue($keyboard);
		}

		$message->setMessage($mess);

		return $message;
	}

	public static function generateFollowUpDroppedMessage(int $callId, int $actionUserId, Chat $chat): Message
	{
		Loader::includeModule('im');

		$user = \Bitrix\Im\User::getInstance($actionUserId);
		$userName = "[user={$actionUserId}]". $user->getFullName(). "[/user]";

		return self::makeMessageWithCallLink(
			'CALL_NOTIFY_TASK_DROPPED_'. ($user->getGender() === 'F' ? 'F' : 'M'),
			$callId,
			$chat,
			['#USER#' => $userName]
		);
	}

	public static function generateTrackDestroyMessage(int $callId, int $actionUserId, Chat $chat): ?Message
	{
		Loader::includeModule('im');

		$user = \Bitrix\Im\User::getInstance($actionUserId);
		$userName = "[user={$actionUserId}]". $user->getFullName(). "[/user]";

		$message = self::makeMessageWithCallLink(
			'CALL_NOTIFY_TASK_DESTROY_'. ($user->getGender() === 'F' ? 'F' : 'M'),
			$callId,
			$chat,
			['#USER#' => $userName]
		);
		$message->getParams()->get(Params::COMPONENT_PARAMS)->setValue([
			'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AI_DESTROY,
			'CALL_ID' => $callId,
		]);

		return $message;
	}

	/**
	 * Generates messages with call audio recordings (one message per track)
	 */
	public static function generateAudioRecordMessages(Call $call, Chat $chat): array
	{
		$callId = $call->getId();

		$trackCollection = \Bitrix\Call\Model\CallTrackTable::query()
			->setSelect(['DISK_FILE_ID'])
			->where('CALL_ID', $callId)
			->where('TYPE', \Bitrix\Call\Track::TYPE_RECORD)
			->whereNotNull('FILE_ID')
			->whereNotNull('DISK_FILE_ID')
			->setOrder(['ID' => 'ASC'])
			->fetchCollection()
		;

		if ($trackCollection->count() === 0)
		{
			return [];
		}

		$linkMess = self::makeCallStartMessageLink($callId, $chat->getId());
		if ($linkMess)
		{
			$messageText0 = self::getPhraseWithCallLink('CALL_NOTIFY_AUDIO_RECORD_WITH_MESSAGE_LINK', $callId, $chat);
		}
		else
		{
			$messageText0 = static::getMessage('CALL_NOTIFY_AUDIO_RECORD_WITHOUT_MESSAGE_LINK', [
				'#CALL_ID#' => $callId,
			]);
		}

		$messages = [];
		foreach ($trackCollection as $track)
		{
			$messageText = $messageText0 . "\n[DISK={$track->getDiskFileId()}]";

			$message = new Message();
			$message->setContextUser($call->getActionUserId() ?: $call->getInitiatorId());
			$message->setAuthorId($call->getActionUserId() ?: $call->getInitiatorId());
			$message->setMessage($messageText);
			$message->uploadFileFromText();
			$message->getParams()->get(Params::COMPONENT_PARAMS)->setValue([
				'MESSAGE_TYPE' => NotifyService::MESSAGE_TYPE_AUDIO_RECORD,
				'CALL_ID' => $callId,
			]);

			$messages[] = $message;
		}

		return $messages;
	}

	protected static function getMessage(string $code, ?array $replace = null): string
	{
		Loc::loadMessages(__FILE__);
		return parent::getMessage($code, $replace);
	}
}
