<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Im\Call\Call;
use Bitrix\Call\Track\TrackCollection;
use Bitrix\Call\Integration\AI\MentionService;
use Bitrix\Call\Integration\AI\Outcome\Transcription;
use Bitrix\Call\Integration\AI\Outcome\OutcomeCollection;

class CallAiComponent extends \CBitrixComponent
{
	protected ?int $callId;
	protected ?Call $call;
	protected ?OutcomeCollection $outcomeCollection;
	protected ?TrackCollection $trackCollection;

	public function executeComponent(): void
	{
		$this->includeComponentLang('class.php');

		global $APPLICATION;

		if (
			$this->checkModules()
			&& $this->prepareParams()
			&& $this->checkAccess()
			&& $this->prepareResult()
		)
		{
			$APPLICATION->SetTitle(Loc::getMessage('CALL_COMPONENT_COPILOT_DETAIL_V2', [
				'#DATE#' => $this->arResult['CALL_DATE']
			]));
			if ($this->arResult['OVERVIEW_VERSION'] <= 1)
			{
				$this->includeComponentTemplate('template.v2');
			}
			else
			{
				$this->includeComponentTemplate('template.v3');
			}
		}
	}

	protected function prepareParams(): bool
	{
		$this->callId = (int)$this->arParams['CALL_ID'];
		if (!$this->callId)
		{
			$this->showError(Loc::getMessage('CALL_COMPONENT_CALL_UNDEFINED'), Loc::getMessage('CALL_COMPONENT_ERROR_DESCRIPTION'));
			return false;
		}

		$this->call = \Bitrix\Im\Call\Registry::getCallWithId($this->callId);
		if (!$this->call)
		{
			$this->showError(Loc::getMessage('CALL_COMPONENT_CALL_UNDEFINED'), Loc::getMessage('CALL_COMPONENT_ERROR_DESCRIPTION'));
			return false;
		}

		return true;
	}

	protected function prepareResult(): bool
	{
		$this->arResult['CALL_ID'] = $this->callId;

		$currentUserId = \Bitrix\Main\Engine\CurrentUser::get()->getId();
		$this->arResult['CURRENT_USER_ID'] = $currentUserId;

		$roles = $this->call->getUserRoles([$currentUserId]);
		$this->arResult['CAN_DELETE'] = isset($roles[$currentUserId]) && in_array($roles[$currentUserId], ['ADMIN', 'MANAGER']);

		$mentionService = MentionService::getInstance();
		$mentionService->loadMentionsForCall($this->callId);

		$this->outcomeCollection = OutcomeCollection::getOutcomesByCallId($this->callId) ?? [];
		foreach ($this->outcomeCollection as $outcome)
		{
			$type = strtoupper($outcome->getType());
			if (isset($this->arResult[$type]))
			{
				continue;// take only one
			}

			$content = $outcome->getSenseContent();
			if ($content)
			{
				/** @var Transcription $transcription */
				if ($content instanceof Transcription)
				{
					$transcription = $content;
				}
				$this->arResult[$type] = $content->toRestFormat(mentionFormat: 'html');
				$this->arResult["{$type}_VERSION"] = $content->getVersion();
			}
		}

		$this->trackCollection = TrackCollection::getRecordings($this->callId) ?? [];
		$this->arResult['RECORD'] = [];
		foreach ($this->trackCollection as $track)
		{
			$this->arResult['RECORD'] = $track->toArray();
			break;// take only one
		}

		if (
			empty($this->arResult['OVERVIEW'])
			&& empty($this->arResult['INSIGHTS'])
			&& empty($this->arResult['SUMMARY'])
			&& empty($this->arResult['TRANSCRIBE'])
			&& empty($this->arResult['RECORD'])
		)
		{
			$this->showError(Loc::getMessage('CALL_COMPONENT_ERROR_TITLE'), Loc::getMessage('CALL_COMPONENT_ERROR_DESCRIPTION'));
			return false;
		}
		if (
			($this->arResult['OVERVIEW_VERSION'] > 1)
			&& empty($this->arResult['EVALUATION'])
		)
		{
			$this->showError(Loc::getMessage('CALL_COMPONENT_ERROR_TITLE'), Loc::getMessage('CALL_COMPONENT_ERROR_DESCRIPTION'));
			return false;
		}

		$feedbackLink = \Bitrix\Call\Library::getCallAiFeedbackUrl($this->callId);
		if ($feedbackLink)
		{
			$this->arResult['FEEDBACK_URL'] = $feedbackLink;
		}

		$startTime = null;
		$endTime = null;
		if ($this->call->getStartDate())
		{
			$startTime = $this->call->getStartDate()->toUserTime();
			$this->arResult['CALL_DATE'] = $this->formatDate($startTime);
			$this->arResult['CALL_START_TIME'] = $this->formatTime($startTime);
		}
		if ($this->call->getEndDate())
		{
			$endTime = $this->call->getEndDate()->toUserTime();
			$this->arResult['CALL_END_TIME'] = $this->formatTime($endTime);
		}
		if ($startTime && $endTime)
		{
			$this->arResult['CALL_DURATION'] = $this->formatDuration($startTime, $endTime);
		}

		$this->arResult['CALL_USERS'] = $this->getCallUsers();
		$this->arResult['USER_COUNT'] = count($this->arResult['CALL_USERS']);

		if (
			$this->arResult['INSIGHTS']['speakerEvaluationAvailable']
			&& !empty($this->arResult['INSIGHTS']['speakerAnalysis'])
		)
		{
			$speakerAnalysis = [];
			$speakerList = $transcription->prepareSpeakersList();
			/** @var array{userId: int, efficiencyValue: float} $speaker */
			foreach ($this->arResult['INSIGHTS']['speakerAnalysis'] as $speaker)
			{
				if (!isset($speakerList[$speaker['userId']]))
				{
					continue;
				}
				$speaker['talkPercentage'] = $speakerList[$speaker['userId']]['talkPercentage'];
				$speaker['duration'] = (int)$speakerList[$speaker['userId']]['duration'];
				$speaker['durationFormat'] = $this->formatLength((int)$speakerList[$speaker['userId']]['duration']);
				$speakerAnalysis[] = $speaker;
			}

			// sort users by efficiencyValue and talkPercentage
			\Bitrix\Main\Type\Collection::sortByColumn(
				array: $speakerAnalysis,
				columns: [
					'talkPercentage' => \SORT_DESC,
					'efficiencyValue' => \SORT_DESC,
				]
			);
			$this->arResult['INSIGHTS']['speakerAnalysis'] = $speakerAnalysis;
		}

		return true;
	}

	protected function getCallUsers(): array
	{
		$callUsers = $this->call->getCallUsers();
		$userData = $this->call->getUserData();
		$users = [];
		foreach ($callUsers as $userId => $callUser)
		{
			if ($callUser->getFirstJoined())
			{
				$users[$userId] = $userData[$userId];
			}
		}
		if (!isset($users[$this->call->getInitiatorId()]))
		{
			$userId = $this->call->getInitiatorId();
			$users[$userId] = $userData[$userId];
		}

		return $users;
	}

	protected function formatDate(DateTime $dateTime): string
	{
		$timestamp = $dateTime->getTimestamp();
		$userCulture = \Bitrix\Main\Context::getCurrent()?->getCulture();
		$isCurrentYear = (date('Y') === date('Y', $timestamp));

		$dateFormat = $isCurrentYear ? $userCulture?->getDayMonthFormat() : $userCulture?->getLongDateFormat();
		$dateFormat .= ', '. $userCulture->getShortTimeFormat();

		return \FormatDate($dateFormat, $timestamp);
	}

	protected function formatTime(DateTime $dateTime): string
	{
		$timestamp = $dateTime->getTimestamp();
		$timeFormat = \Bitrix\Main\Context::getCurrent()?->getCulture()?->getShortTimeFormat() ?? 'H:i';

		return \FormatDate($timeFormat, $timestamp);
	}

	protected function formatDuration(DateTime $startTime, DateTime $endTime): string
	{
		$interval = $startTime->getDiff($endTime);
		[$hours, $minutes, $seconds] = explode(' ', $interval->format('%H %I %S'));

		return $this->formatInterval((int)$hours, (int)$minutes, (int)$seconds);
	}

	protected function formatLength(int $duration): string
	{
		$hours = $minutes = $seconds = 0;
		if ($duration < 60)
		{
			$seconds = $duration;
		}
		else
		{
			$hours = floor($duration / 3600);
			if ($hours > 0)
			{
				$duration -= $hours * 3600;
			}
			$minutes = floor($duration / 60);
		}

		return $this->formatInterval($hours, $minutes, $seconds);
	}

	protected function formatInterval(int $hours = 0, int $minutes = 0, int $seconds = 0): string
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/im/lib/call/integration/chat.php');

		$result = [];
		if ($hours > 0)
		{
			$result[] = Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_DURATION_HOURS', [
				'#HOURS#' => $hours
			]);
		}
		if ($minutes > 0)
		{
			$result[] = Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_DURATION_MINUTES', [
				'#MINUTES#' => $minutes
			]);
		}
		if ($seconds > 0 && !($hours > 0))
		{
			$result[] = Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_DURATION_SECONDS', [
				'#SECONDS#' => $seconds
			]);
		}

		return implode(' ', $result);
	}

	protected function checkAccess(): bool
	{
		$currentUserId = \Bitrix\Main\Engine\CurrentUser::get()->getId();
		$hasAccess = $this->call->checkAccess($currentUserId);
		if (!$hasAccess)
		{
			$this->showError(Loc::getMessage('CALL_COMPONENT_ACCESS_DENIED'), Loc::getMessage('CALL_COMPONENT_ERROR_DESCRIPTION'));
		}

		return $hasAccess;
	}

	protected function showError(string $error, string $errorDesc = ''): void
	{
		$this->arResult['ERROR'] = $error;
		$this->arResult['ERROR_DESC'] = $errorDesc;
		$this->includeComponentTemplate('error');
	}

	protected function checkModules(): bool
	{
		if (!Loader::includeModule('im'))
		{
			$this->showError(Loc::getMessage('CALL_COMPONENT_MODULE_IM_NOT_INSTALLED'));

			return false;
		}

		if (!Loader::includeModule('call'))
		{
			$this->showError(Loc::getMessage('CALL_COMPONENT_MODULE_CALL_NOT_INSTALLED'));

			return false;
		}

		return true;
	}
}