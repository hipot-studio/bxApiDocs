<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Folder\System\ChannelFolder;
use Bitrix\Im\V2\Folder\System\CollabFolder;
use Bitrix\Im\V2\Folder\System\CopilotFolder;
use Bitrix\Im\V2\Folder\System\MeetingRootFolder;
use Bitrix\Im\V2\Folder\System\TaskRootFolder;
use Bitrix\Im\V2\Folder\System\VibecodeAppFolder;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;

/**
 * Folder analytics of the module (EVENT-01) and the only place where a domain source code becomes a
 * value of the report dictionary (DTO-01).
 *
 * Deliberately not an {@see AbstractAnalytics}: that one is built around a Chat, and a folder has
 * none. The user is taken as an explicit argument for a neighbouring reason - the event is sent from
 * a background job, which runs after the answer, where a guess at the global user is out of place.
 */
final class FolderAnalytics
{
	private const TOOL = 'im';
	private const CATEGORY = 'folders';
	private const SUBMIT_ALL_SETTINGS = 'submit_all_settings';
	private const SUBMIT_CREATE_FOLDER = 'submit_create_folder';
	private const DELETE_FOLDER = 'delete_folder';
	private const ADD_CHAT = 'add_chat';
	private const HIDE_FOLDER = 'hide_folder';
	private const SHOW_FOLDER = 'show_folder';
	private const DISABLED_FOLDERS_PREFIX = 'disabledFolders_';
	private const RETURNED_FOLDERS_PREFIX = 'returnedFolders_';
	private const CHAT_COUNT_PREFIX = 'cnt_';
	private const TYPE_EMPTY = 'empty';
	private const TYPE_NON_EMPTY = 'non_empty';
	private const TYPE_SINGLE = 'single';
	private const TYPE_BULK = 'bulk';

	/**
	 * Stored source code ({@see \Bitrix\Im\V2\Folder\System\SystemFolder::getCode()}) => value of the
	 * report dictionary. Exactly the canonical registry
	 * ({@see \Bitrix\Im\V2\Folder\System\AllFolder::getDefaultDefinition}) minus the mandatory
	 * source, which is hidden from the user and is never named in a label.
	 *
	 * Keys are the constants of the source classes and not literals: a code renamed in the domain
	 * has to break the build instead of silently switching a value off.
	 *
	 * No value may carry an underscore - the p3 slot spends its single allowed one on the prefix of
	 * the list it carries, and a second one invalidates the whole event
	 * ({@see AnalyticsEvent::validatePField()}).
	 */
	private const SOURCE_TYPE_MAP = [
		MeetingRootFolder::CODE => 'meetings',
		TaskRootFolder::CODE => 'tasks',
		CopilotFolder::CODE => 'copilot',
		ChannelFolder::CODE => 'channels',
		CollabFolder::CODE => 'projects',
		VibecodeAppFolder::CODE => 'vibecode',
	];

	public function __construct(private readonly int $userId)
	{
	}

	/**
	 * One event per one save of the composition of "All", a save that changed nothing included.
	 *
	 * @param string[]|null $disabledSourceCodes stored codes of the sources left disabled by the
	 *        save; null means the composition was never counted, so nothing was written
	 * @param int|string|null $errorCode code of the refusal, read only when nothing was written
	 */
	public function addSubmitAllSettings(?array $disabledSourceCodes, int|string|null $errorCode = null): void
	{
		$this->sendInBackground(function () use ($disabledSourceCodes, $errorCode): void {
			$this->sendSubmitAllSettings($disabledSourceCodes, self::normalizeErrorCode($errorCode));
		});
	}

	/**
	 * @param string[]|null $disabledSourceCodes
	 */
	private function sendSubmitAllSettings(?array $disabledSourceCodes, ?string $errorCode): void
	{
		$event = new AnalyticsEvent(self::SUBMIT_ALL_SETTINGS, self::TOOL, self::CATEGORY);
		$event->setUserId($this->userId);

		if ($disabledSourceCodes === null)
		{
			$event->setStatus(self::buildErrorStatus($errorCode))->send();

			return;
		}

		$event
			->setStatus(AnalyticsEvent::STATUS_SUCCESS)
			->setP3(self::buildSourceListSlot(self::DISABLED_FOLDERS_PREFIX, $disabledSourceCodes))
			->send()
		;
	}

	/**
	 * A list of sources in a p-slot, shared by every label that carries one: the prefix names which
	 * list it is, and the values of the dictionary follow it as JSON.
	 *
	 * Values sorted by the alphabet of the values and not of the domain codes - the two do not
	 * agree, and the reports are read in the values.
	 *
	 * @param string $prefix the single underscore the slot is allowed
	 * @param string[] $sourceCodes stored codes; a code outside the dictionary is dropped
	 */
	private static function buildSourceListSlot(string $prefix, array $sourceCodes): string
	{
		$values = [];
		foreach ($sourceCodes as $code)
		{
			$value = self::SOURCE_TYPE_MAP[$code] ?? null;
			if ($value !== null)
			{
				$values[] = $value;
			}
		}

		sort($values);

		return $prefix . json_encode(array_values($values));
	}

	/**
	 * One event per one call of {@see \Bitrix\Im\V2\Folder\FolderService::create()}, that is per one
	 * press of "Create", a refusal included (EVENT-02).
	 *
	 * @param int[] $chatIds initial composition asked for by the user. The folder is stored empty and
	 *        filled a step later, so "empty or not" is the input of the call and never the
	 *        composition of the result
	 * @param int|string|null $errorCode code of the refusal, null when the folder was stored. A folder
	 *        that reaches the result together with an error of its composition is still created
	 */
	public function addSubmitCreateFolder(array $chatIds, int|string|null $errorCode = null): void
	{
		$this->sendInBackground(function () use ($chatIds, $errorCode): void {
			$this->sendSubmitCreateFolder($chatIds, self::normalizeErrorCode($errorCode));
		});
	}

	/**
	 * @param int[] $chatIds
	 */
	private function sendSubmitCreateFolder(array $chatIds, ?string $errorCode): void
	{
		(new AnalyticsEvent(self::SUBMIT_CREATE_FOLDER, self::TOOL, self::CATEGORY))
			->setUserId($this->userId)
			->setType(self::buildCompositionType($chatIds))
			->setStatus(self::buildStatus($errorCode))
			->send()
		;
	}

	/**
	 * One event per one folder deleted by the user (EVENT-03).
	 *
	 * @param int[] $chatIds composition of the folder read before the first mutation of the delete:
	 *        by the time the delete succeeds the memberships are already gone
	 */
	public function addDeleteFolder(array $chatIds): void
	{
		$this->sendInBackground(function () use ($chatIds): void {
			$this->sendDeleteFolder($chatIds);
		});
	}

	/**
	 * The status is left to the core: the event is sent on a successful delete only, and success is
	 * exactly what the core puts there by default.
	 *
	 * @param int[] $chatIds
	 */
	private function sendDeleteFolder(array $chatIds): void
	{
		(new AnalyticsEvent(self::DELETE_FOLDER, self::TOOL, self::CATEGORY))
			->setUserId($this->userId)
			->setType(self::buildCompositionType($chatIds))
			->send()
		;
	}

	/**
	 * One event per one folder that receives chats (EVENT-04): a chat added to three folders at once
	 * is three facts of "a chat is in a folder", and the event carries no folder to tell them apart.
	 *
	 * @param int[] $chatIds chats of this call, whose count reaches the report as is
	 */
	public function addAddChat(array $chatIds): void
	{
		$this->sendInBackground(function () use ($chatIds): void {
			$this->sendAddChat($chatIds);
		});
	}

	/**
	 * @param int[] $chatIds
	 */
	private function sendAddChat(array $chatIds): void
	{
		(new AnalyticsEvent(self::ADD_CHAT, self::TOOL, self::CATEGORY))
			->setUserId($this->userId)
			->setType(self::buildChatSetType($chatIds))
			->setStatus(AnalyticsEvent::STATUS_SUCCESS)
			->setP3(self::buildChatCountSlot($chatIds))
			->send()
		;
	}

	/**
	 * One event per one hide of a folder, a refusal included: both paths of the hide send it from
	 * the thin wrapper of their write
	 * ({@see \Bitrix\Im\V2\Folder\Visibility\FolderVisibilityService::hide()}), so the count of the
	 * labels is the count of the decisions of the user and not of the folders or the sections.
	 *
	 * @param string[]|null $returnedSourceCodes stored codes of the sources the hide switched back
	 *        on to keep the chats of the hidden sections reachable (AC-013). The empty array means
	 *        the hide went through and no source had to come back; null means it never reached the
	 *        invariant, so nothing was written
	 * @param int|string|null $errorCode code of the refusal, read only when nothing was written
	 */
	public function addHideFolder(?array $returnedSourceCodes, int|string|null $errorCode = null): void
	{
		$this->sendInBackground(function () use ($returnedSourceCodes, $errorCode): void {
			$this->sendHideFolder($returnedSourceCodes, self::normalizeErrorCode($errorCode));
		});
	}

	/**
	 * The return of the sections is a parameter of the hide and not a label of its own: one save of
	 * the "Folder list" screen can hide several sections at once, and a label per section would
	 * count the sections where the report counts the presses.
	 *
	 * @param string[]|null $returnedSourceCodes
	 */
	private function sendHideFolder(?array $returnedSourceCodes, ?string $errorCode): void
	{
		$event = new AnalyticsEvent(self::HIDE_FOLDER, self::TOOL, self::CATEGORY);
		$event->setUserId($this->userId);

		if ($returnedSourceCodes === null)
		{
			$event->setStatus(self::buildErrorStatus($errorCode))->send();

			return;
		}

		$event
			->setStatus(AnalyticsEvent::STATUS_SUCCESS)
			->setP3(self::buildSourceListSlot(self::RETURNED_FOLDERS_PREFIX, $returnedSourceCodes))
			->send()
		;
	}

	/**
	 * One event per one folder brought back to the navigation, a refusal included. The counterpart
	 * of the hide, and it carries no list: nothing comes back to the composition of "All" when a
	 * folder returns - the source that was switched on to keep its chats reachable stays on, and
	 * the user decides about it on the composition screen.
	 *
	 * @param int|string|null $errorCode code of the refusal, null when the folder came back
	 */
	public function addShowFolder(int|string|null $errorCode = null): void
	{
		$this->sendInBackground(function () use ($errorCode): void {
			$this->sendShowFolder(self::normalizeErrorCode($errorCode));
		});
	}

	private function sendShowFolder(?string $errorCode): void
	{
		(new AnalyticsEvent(self::SHOW_FOLDER, self::TOOL, self::CATEGORY))
			->setUserId($this->userId)
			->setStatus(self::buildStatus($errorCode))
			->send()
		;
	}

	/**
	 * Every slot is built by a pure method of its own for the same reason the p3 slot of EVENT-01 is:
	 * the send stays silent on a stand without a writable ANALYTICS_V2_FILENAME, so the form of a
	 * label is checkable only here.
	 *
	 * @param int[] $chatIds
	 */
	private static function buildCompositionType(array $chatIds): string
	{
		return empty($chatIds) ? self::TYPE_EMPTY : self::TYPE_NON_EMPTY;
	}

	/**
	 * @param int[] $chatIds
	 */
	private static function buildChatSetType(array $chatIds): string
	{
		return count($chatIds) === 1 ? self::TYPE_SINGLE : self::TYPE_BULK;
	}

	/**
	 * A single underscore, the one of the prefix: a second one invalidates the whole event
	 * ({@see AnalyticsEvent::validatePField()}).
	 *
	 * @param int[] $chatIds
	 */
	private static function buildChatCountSlot(array $chatIds): string
	{
		return self::CHAT_COUNT_PREFIX . count($chatIds);
	}

	private static function buildStatus(?string $errorCode): string
	{
		return
			$errorCode === null
				? AnalyticsEvent::STATUS_SUCCESS
				: self::buildErrorStatus($errorCode)
		;
	}

	/**
	 * The form of a refusal, written down once for both events that carry one. EVENT-01 reaches it
	 * past {@see buildStatus()}: a null code means "the composition was never counted" there and
	 * not a success, so the two labels share the rule and not the reading of the null.
	 */
	private static function buildErrorStatus(?string $errorCode): string
	{
		return 'error_' . mb_strtolower((string)$errorCode);
	}

	/**
	 * The core declares the code of an error as mixed ({@see \Bitrix\Main\Error::getCode()}), so
	 * the facade takes it as it comes and narrows it here. A TypeError on the argument of a label
	 * is thrown synchronously - the observing label would take down the operation it observes.
	 */
	private static function normalizeErrorCode(int|string|null $errorCode): ?string
	{
		return $errorCode === null ? null : (string)$errorCode;
	}

	/**
	 * Only a background job sends: a synchronous write would add file IO and a bitrix24 module load
	 * to the hot path of the operation being reported ({@see AnalyticsEvent::send()}).
	 *
	 * A label with no user is not sent at all. Three of the four take the user from
	 * {@see \Bitrix\Im\V2\Folder\BaseFolder::getUserId()}, which is nullable, so an unhydrated
	 * folder would send a row of the user 0 into the reports for someone to explain later.
	 */
	private function sendInBackground(callable $send): void
	{
		if ($this->userId <= 0)
		{
			return;
		}

		Application::getInstance()->addBackgroundJob($send, [], Application::JOB_PRIORITY_LOW);
	}
}
