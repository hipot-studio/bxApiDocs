<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Tasks;

use Bitrix\Mail\Integration\Crm\Activity;
use Bitrix\Mail\Integration\Intranet\Secretary;
use Bitrix\Mail\MessageAccess;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\Internals\TaskMailSourceTable;
use Bitrix\Mail\Public\Service\Access\MessageAccessService;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Public\Service\Access\TaskAccessService;

final class TaskMailSourceService
{
	// Forward-looking: Mail still creates tasks via the legacy UF_MAIL_MESSAGE field, so this
	// source type is not emitted from the UI yet. It is reserved for moving Mail onto the shared
	// source storage; see RunMail for the (currently unreachable) access-granting branch.
	public const SOURCE_TYPE_MAIL_MESSAGE = 'mail_message';
	public const SOURCE_TYPE_CRM_ACTIVITY = 'crm_activity';

	public static function getEmailDataByTaskId(
		int $taskId,
		?int $userId = null,
		bool $withBody = true,
	): ?array
	{
		if ($taskId <= 0)
		{
			return null;
		}

		$source = TaskMailSourceTable::query()
			->setSelect(['TASK_ID', 'SOURCE_TYPE', 'SOURCE_ID'])
			->where('TASK_ID', $taskId)
			->fetch()
		;

		if (!is_array($source))
		{
			return null;
		}

		$sourceId = (int)($source['SOURCE_ID'] ?? 0);

		return self::getEmailDataBySource(
			(string)($source['SOURCE_TYPE'] ?? ''),
			$sourceId,
			$userId,
			$taskId,
			$withBody,
		);
	}

	public static function getEmailDataBySource(
		string $sourceType,
		int $sourceId,
		?int $userId = null,
		int $taskId = 0,
		bool $withBody = true,
	): ?array
	{
		return match ($sourceType)
		{
			self::SOURCE_TYPE_MAIL_MESSAGE => self::buildMailMessageData($taskId, $sourceId, $userId, $withBody),
			self::SOURCE_TYPE_CRM_ACTIVITY => self::buildCrmActivityData($taskId, $sourceId, $userId, $withBody),
			default => null,
		};
	}

	public static function canReadSourceByTaskId(int $taskId, int $sourceId, int $userId): bool
	{
		if ($taskId <= 0 || $sourceId <= 0 || $userId <= 0)
		{
			return false;
		}

		$source = self::findBoundSourceRow($taskId, $sourceId);
		if ($source === null)
		{
			return false;
		}

		$sourceType = (string)($source['SOURCE_TYPE'] ?? '');
		if ($sourceType === self::SOURCE_TYPE_MAIL_MESSAGE)
		{
			try
			{
				$message = Secretary::getMessage($sourceId);

				[$canRead] = (new MessageAccessService())->canRead(
					mailboxId: $message->getMailboxId(),
					messageId: $sourceId,
					mailUserFieldId: 0,
					entityId: $taskId,
					userId: $userId,
					entityType: MessageAccessTable::ENTITY_TYPE_TASKS_TASK,
				);

				return $canRead;
			}
			catch (\Throwable)
			{
				return false;
			}
		}

		if (self::canReadSource($sourceType, $sourceId, $userId))
		{
			return true;
		}

		// A CRM-sourced email follows the task: its details link is signed with a task-scoped token,
		// so CRM permissions are not required to read it.
		return $sourceType === self::SOURCE_TYPE_CRM_ACTIVITY && self::canReadTask($taskId, $userId);
	}

	/**
	 * Checks the source against the permissions of its own module (CRM or mail). It is also the gate
	 * for storing the source on task creation, so it intentionally takes no $taskId: with a task
	 * access fallback here a creator without CRM permissions could bind a foreign CRM activity to
	 * their own task and thus grant themselves access to it.
	 */
	public static function canReadSource(string $sourceType, int $sourceId, int $userId): bool
	{
		if ($sourceId <= 0 || $userId <= 0)
		{
			return false;
		}

		try
		{
			return match ($sourceType)
			{
				self::SOURCE_TYPE_MAIL_MESSAGE => MessageAccess::createByMessageId($sourceId, $userId)->canViewMessage(),
				self::SOURCE_TYPE_CRM_ACTIVITY => self::getCrmActivityDetailsUrl($sourceId, $userId) !== null,
				default => false,
			};
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public static function saveSource(int $taskId, string $sourceType, int $sourceId): Result
	{
		$result = new Result();

		if ($taskId <= 0)
		{
			return $result->addError(new Error('Task id is required.', 'TASK_ID_REQUIRED'));
		}

		if ($sourceId <= 0)
		{
			return $result->addError(new Error('Source id is required.', 'SOURCE_ID_REQUIRED'));
		}

		if (!in_array($sourceType, self::getSupportedSourceTypes(), true))
		{
			return $result->addError(new Error('Source type is not supported.', 'SOURCE_TYPE_NOT_SUPPORTED'));
		}

		$fields = [
			'TASK_ID' => $taskId,
			'SOURCE_TYPE' => $sourceType,
			'SOURCE_ID' => $sourceId,
		];

		$exists = TaskMailSourceTable::query()
			->setSelect(['TASK_ID'])
			->where('TASK_ID', $taskId)
			->fetch()
		;

		$saveResult = is_array($exists)
			? TaskMailSourceTable::update($taskId, [
				'SOURCE_TYPE' => $sourceType,
				'SOURCE_ID' => $sourceId,
			])
			: TaskMailSourceTable::add($fields)
		;

		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		return $result;
	}

	public static function grantMailMessageAccess(int $taskId, int $messageId, int $userId): Result
	{
		$result = new Result();

		if ($taskId <= 0 || $messageId <= 0 || $userId <= 0)
		{
			return $result->addError(new Error('Invalid mail message binding.', 'INVALID_MAIL_MESSAGE_BINDING'));
		}

		if (!MessageAccess::createByMessageId($messageId, $userId)->canViewMessage())
		{
			return $result->addError(new Error('Access denied.', 'ACCESS_DENIED'));
		}

		$granted = Secretary::provideAccessToMessage(
			$messageId,
			MessageAccessTable::ENTITY_TYPE_TASKS_TASK,
			$taskId,
			$userId,
		);

		if (!$granted)
		{
			return $result->addError(new Error('Access grant failed.', 'ACCESS_GRANT_FAILED'));
		}

		return $result;
	}

	/**
	 * Best-effort: marks the mail message behind a CRM activity as the one a task was created from.
	 * The mail client and the search read this binding to show that an email already has a task.
	 *
	 * It grants no read permission on the message, and the email read-path does not rely on it: a task
	 * viewer without CRM permissions opens a CRM-sourced email by a task-scoped token instead
	 * {@see self::getCrmActivityDetailsUrl}.
	 *
	 * Limitation: {@see Secretary::provideAccessToMessage} creates the binding only when $userId is the
	 * mailbox owner; for anyone else no binding is created (a limitation of the mechanism itself).
	 */
	public static function bindCrmActivityMailMessageToTask(int $taskId, int $activityId, int $userId): void
	{
		if ($taskId <= 0 || $activityId <= 0 || $userId <= 0)
		{
			return;
		}

		try
		{
			$mailMessageId = Activity::resolveMailMessageId($activityId);
			if ($mailMessageId <= 0)
			{
				return;
			}

			Secretary::provideAccessToMessage(
				$mailMessageId,
				MessageAccessTable::ENTITY_TYPE_TASKS_TASK,
				$taskId,
				$userId,
			);
		}
		catch (\Throwable)
		{
			// Best-effort: a missing binding must not block task creation.
		}
	}

	public static function deleteByTaskId(int $taskId): Result
	{
		$result = new Result();

		if ($taskId <= 0)
		{
			return $result;
		}

		$row = TaskMailSourceTable::query()
			->setSelect(['TASK_ID'])
			->where('TASK_ID', $taskId)
			->fetch()
		;

		if (!is_array($row))
		{
			return $result;
		}

		$deleteResult = TaskMailSourceTable::delete($taskId);
		if (!$deleteResult->isSuccess())
		{
			return $result->addErrors($deleteResult->getErrors());
		}

		return $result;
	}

	private static function buildMailMessageData(
		int $taskId,
		int $messageId,
		?int $userId = null,
		bool $withBody = true,
	): ?array
	{
		if (
			$messageId <= 0
			|| ($userId !== null && !MessageAccess::createByMessageId($messageId, $userId)->canViewMessage())
		)
		{
			return null;
		}

		$message = Secretary::getMessage($messageId);

		return [
			'id' => $message->getId(),
			'taskId' => $taskId,
			'sourceType' => self::SOURCE_TYPE_MAIL_MESSAGE,
			'sourceId' => $message->getId(),
			'mailboxId' => $message->getMailboxId(),
			'title' => Emoji::decode($message->getSubject()),
			'body' => $withBody ? Emoji::decode($message->getBody()) : null,
			'from' => $message->getFrom(),
			'dateTs' => $message->getDate()->getTimestamp(),
			'link' => Secretary::getDirectMessageUrl($messageId),
		];
	}

	private static function buildCrmActivityData(
		int $taskId,
		int $activityId,
		?int $userId = null,
		bool $withBody = true,
	): ?array
	{
		if ($activityId <= 0)
		{
			return null;
		}

		$activity = Activity::getActivity($activityId);
		if ($activity === null)
		{
			return null;
		}

		$detailsUrl = self::getCrmActivityDetailsUrl($activityId, $userId, $activity, $taskId);
		if ($detailsUrl === null)
		{
			return null;
		}

		$body = null;
		if ($withBody)
		{
			$body = self::makePlainText(Activity::getBodyHtml($activity));
		}

		$emailMeta = self::extractEmailMeta($activity['SETTINGS'] ?? []);

		return [
			'id' => null,
			'taskId' => $taskId,
			'sourceType' => self::SOURCE_TYPE_CRM_ACTIVITY,
			'sourceId' => $activityId,
			'mailboxId' => null,
			'title' => (string)($activity['SUBJECT'] ?? ''),
			'body' => $body,
			'from' => self::stringifyAddress($emailMeta['from'] ?? $emailMeta['__email'] ?? ''),
			'dateTs' => self::getTimestamp($activity['START_TIME'] ?? null),
			'link' => $detailsUrl,
		];
	}

	private static function getCrmActivityDetailsUrl(
		int $activityId,
		?int $userId = null,
		?array $activity = null,
		int $taskId = 0,
	): ?string
	{
		$activity ??= Activity::getActivity($activityId);
		if (!Activity::isEmailActivity($activity))
		{
			return null;
		}

		if ($userId !== null && $userId > 0)
		{
			$detailsUrl = Activity::getDetailsUrl($activityId, $userId);
			if ($detailsUrl !== null)
			{
				return $detailsUrl;
			}

			if (!self::isCrmActivityBoundToTask($taskId, $activityId))
			{
				return null;
			}

			return Activity::getTaskScopedDetailsUrl($activityId, $taskId);
		}

		return '/crm/activity/details/' . $activityId . '/';
	}

	private static function isCrmActivityBoundToTask(int $taskId, int $activityId): bool
	{
		$source = self::findBoundSourceRow($taskId, $activityId);

		return $source !== null
			&& (string)($source['SOURCE_TYPE'] ?? '') === self::SOURCE_TYPE_CRM_ACTIVITY;
	}

	/**
	 * Returns the stored source row of the task, but only when it really points at the given source id.
	 */
	private static function findBoundSourceRow(int $taskId, int $sourceId): ?array
	{
		if ($taskId <= 0 || $sourceId <= 0)
		{
			return null;
		}

		$source = TaskMailSourceTable::query()
			->setSelect(['SOURCE_TYPE', 'SOURCE_ID'])
			->where('TASK_ID', $taskId)
			->fetch()
		;

		if (!is_array($source) || (int)($source['SOURCE_ID'] ?? 0) !== $sourceId)
		{
			return null;
		}

		return $source;
	}

	private static function canReadTask(int $taskId, int $userId): bool
	{
		if ($taskId <= 0 || $userId <= 0 || !Loader::includeModule('tasks'))
		{
			return false;
		}

		return (new TaskAccessService())->canRead($userId, $taskId);
	}

	private static function getSupportedSourceTypes(): array
	{
		return [
			self::SOURCE_TYPE_MAIL_MESSAGE,
			self::SOURCE_TYPE_CRM_ACTIVITY,
		];
	}

	private static function extractEmailMeta(mixed $settings): array
	{
		if (is_string($settings))
		{
			$settings = unserialize($settings, ['allowed_classes' => false]);
		}

		if (!is_array($settings))
		{
			return [];
		}

		$emailMeta = $settings['EMAIL_META'] ?? [];

		return is_array($emailMeta) ? $emailMeta : [];
	}

	private static function stringifyAddress(mixed $value): string
	{
		if (is_array($value))
		{
			return implode(', ', array_map('strval', $value));
		}

		return (string)$value;
	}

	private static function makePlainText(string $bodyHtml): string
	{
		$body = preg_replace('#<(style|script)[^>]*>.*?</\\1>#si', '', $bodyHtml) ?? '';
		// Also drop the tail of an unclosed <style>/<script> so its raw content does not leak.
		$body = preg_replace('#<(style|script)[^>]*>.*$#si', '', $body) ?? $body;
		$body = str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $body);

		return html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
	}

	private static function getTimestamp(mixed $date): ?int
	{
		if ($date instanceof DateTime)
		{
			return $date->getTimestamp();
		}

		if ($date instanceof \DateTimeInterface)
		{
			return $date->getTimestamp();
		}

		if (is_string($date) && $date !== '')
		{
			$timestamp = strtotime($date);

			return $timestamp === false ? null : $timestamp;
		}

		return null;
	}
}

