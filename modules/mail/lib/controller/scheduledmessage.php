<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Intranet;
use Bitrix\Mail\Internals\ScheduledMessageTable;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageErrorText;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageService;
use Bitrix\Main;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class ScheduledMessage extends Controller
{
	public const ERROR_FEATURE_UNAVAILABLE = 'feature_unavailable';

	protected function getDefaultPreFilters(): array
	{
		$filters = [
			new Main\Engine\ActionFilter\ContentType([
				Main\Engine\ActionFilter\ContentType::JSON,
				'application/x-www-form-urlencoded',
			]),
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
			new Main\Engine\ActionFilter\Csrf(),
		];

		// Pre-filters are built before any action runs, so a class of a module that is absent would take down
		// every request of the feature at once. The drafts this feature works with ask the loader the same way.
		if (Loader::includeModule('intranet'))
		{
			$filters[] = new Intranet\ActionFilter\IntranetUser();
		}

		return $filters;
	}

	/**
	 * The draft of the form is optional input: a letter written without one — an answer, a form of a screen
	 * that keeps no draft — is scheduled by a request that names neither field.
	 */
	public function scheduleAction(
		int $mailboxId,
		array $sender,
		array $recipients,
		string $subject,
		string $body,
		string $scheduledAt,
		string $timezoneId,
		array $attachments = [],
		?int $draftId = null,
		?int $draftRevision = null,
		?string $inReplyTo = null,
		bool $saveAsTemplate = false,
	): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		$dateTime = $this->parseAbsoluteTime($scheduledAt);
		if ($dateTime === null)
		{
			return [];
		}

		$result = $this->getService()->create(
			$this->getUserId(),
			[
				'MAILBOX_ID' => $mailboxId,
				'SENDER' => $sender,
				'RECIPIENTS' => $recipients,
				'SUBJECT' => $subject,
				'BODY' => $body,
				'ATTACHMENTS' => $this->mapAttachmentsToStorage($attachments),
				'SCHEDULED_AT' => $dateTime,
				'AUTHOR_TIMEZONE_ID' => $timezoneId,
				'IN_REPLY_TO' => $inReplyTo,
				...($saveAsTemplate ? [ScheduledMessageService::SAVE_AS_TEMPLATE_KEY => true] : []),
			],
			$draftId,
			$draftRevision,
		);

		return $this->scheduledMessageResponse($result);
	}

	public function listAction(
		int $mailboxId,
		int $offset = 0,
		int $limit = 50,
		array $statuses = [],
	): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		$result = $this->getService()->getList(
			$this->getUserId(),
			$mailboxId,
			array_values(array_map('strval', $statuses)),
			max(0, $offset),
			max(1, min(100, $limit)),
			withAttachments: true,
		);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$data = $result->getData();

		return [
			'items' => array_map($this->mapScheduledMessageListItem(...), (array)($data['items'] ?? [])),
			'total' => (int)($data['total'] ?? 0),
			'hasMore' => (bool)($data['hasMore'] ?? false),
		];
	}

	public function getAction(int $id): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		return $this->scheduledMessageResponse($this->getService()->get($this->getUserId(), $id));
	}

	public function updateAction(
		int $id,
		int $revision,
		array $sender,
		array $recipients,
		string $subject,
		string $body,
		string $scheduledAt,
		string $timezoneId,
		array $attachments = [],
		?array $existingAttachmentIds = null,
		?string $inReplyTo = null,
	): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		$dateTime = $this->parseAbsoluteTime($scheduledAt);
		if ($dateTime === null)
		{
			return [];
		}

		$data = [
			'SENDER' => $sender,
			'RECIPIENTS' => $recipients,
			'SUBJECT' => $subject,
			'BODY' => $body,
			'ATTACHMENTS' => $this->mapAttachmentsToStorage($attachments),
			'SCHEDULED_AT' => $dateTime,
			'AUTHOR_TIMEZONE_ID' => $timezoneId,
		];
		if ($inReplyTo !== null)
		{
			$data['IN_REPLY_TO'] = $inReplyTo;
		}
		if ($existingAttachmentIds !== null)
		{
			$data['EXISTING_ATTACHMENT_IDS'] = $existingAttachmentIds;
		}
		$result = $this->getService()->update($this->getUserId(), $id, $revision, $data);

		return $this->scheduledMessageResponse($result);
	}

	public function cancelAction(int $id, int $revision): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		return $this->scheduledMessageResponse(
			$this->getService()->cancel($this->getUserId(), $id, $revision),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	public function cancelBatchAction(array $items): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		$result = $this->getService()->cancelBatch($this->getUserId(), $items);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return ['results' => $this->mapBatchResults((array)($result->getData()['results'] ?? []))];
	}

	public function retryAction(int $id, int $revision): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		return $this->scheduledMessageResponse(
			$this->getService()->retry($this->getUserId(), $id, $revision),
		);
	}

	public function sendNowAction(int $id, int $revision): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		return $this->scheduledMessageResponse(
			$this->getService()->sendNow($this->getUserId(), $id, $revision),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	public function sendNowBatchAction(array $items): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		$result = $this->getService()->sendNowBatch($this->getUserId(), $items);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return ['results' => $this->mapBatchResults((array)($result->getData()['results'] ?? []))];
	}

	protected function getService(): ScheduledMessageService
	{
		return new ScheduledMessageService();
	}

	protected function isFeatureAvailable(): bool
	{
		return Feature::isScheduledMessageAvailable();
	}

	private function ensureFeatureAvailable(): bool
	{
		if ($this->isFeatureAvailable())
		{
			return true;
		}

		$this->addError(new Error('Feature is not available.', self::ERROR_FEATURE_UNAVAILABLE));

		return false;
	}

	/**
	 * The letter of an item of a batch is answered by the same view a row of the list is: a batch of fifty
	 * would otherwise carry fifty bodies, and the moments of the row would leave as `{}` — `Type\Date` names
	 * itself in no JSON. The reader of the answer reads the identifier, the outcome and the errors.
	 *
	 * @param array<int, mixed> $results
	 * @return array<int, mixed>
	 */
	private function mapBatchResults(array $results): array
	{
		foreach ($results as $index => $item)
		{
			$row = is_array($item) ? ($item['data']['scheduledMessage'] ?? null) : null;
			if (is_array($row))
			{
				$results[$index]['data']['scheduledMessage'] = $this->mapScheduledMessageListItem($row);
			}
		}

		return $results;
	}

	private function scheduledMessageResponse(Result $result): array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$row = $result->getData()['scheduledMessage'] ?? null;
		if (!is_array($row))
		{
			$this->addError(new Error('Scheduled message is not found.', ScheduledMessageService::ERROR_NOT_FOUND));

			return [];
		}

		$response = ['scheduledMessage' => $this->mapScheduledMessage($row)];
		$data = $result->getData();
		if (isset($data['templateSaveWarning']))
		{
			$response['templateSaveWarning'] = (bool)$data['templateSaveWarning'];
		}
		if (isset($data['draftId']))
		{
			$response['draftId'] = (int)$data['draftId'];
		}
		if (isset($data['draft']) && is_array($data['draft']))
		{
			$response['draft'] = $data['draft'];
		}

		return $response;
	}

	/**
	 * A page of a hundred would otherwise carry a hundred letters, and no reader of the list renders one:
	 * the body belongs to the single message the view screen asks for.
	 *
	 * @param array<string, mixed> $row
	 */
	private function mapScheduledMessageListItem(array $row): array
	{
		$item = $this->mapScheduledMessage($row);
		unset($item['body']);

		return $item;
	}

	/** @param array<string, mixed> $row */
	private function mapScheduledMessage(array $row): array
	{
		$status = (string)($row['STATUS'] ?? '');
		$canManage = (int)($row['AUTHOR_ID'] ?? 0) === $this->getUserId()
			&& in_array($status, [ScheduledMessageTable::STATUS_SCHEDULED, ScheduledMessageTable::STATUS_ERROR], true);
		$actions = $canManage ? ['update', 'cancel'] : [];
		if ($canManage && $status === ScheduledMessageTable::STATUS_ERROR)
		{
			$actions[] = 'retry';
		}

		return [
			'id' => (int)($row['ID'] ?? 0),
			'revision' => (int)($row['REVISION'] ?? 0),
			'status' => $status,
			'mailboxId' => (int)($row['MAILBOX_ID'] ?? 0),
			'authorId' => (int)($row['AUTHOR_ID'] ?? 0),
			'sender' => (array)($row['SENDER'] ?? []),
			'recipients' => (array)($row['RECIPIENTS'] ?? []),
			'subject' => (string)($row['SUBJECT'] ?? ''),
			'body' => (string)($row['BODY'] ?? ''),
			'inReplyTo' => isset($row['IN_REPLY_TO']) ? (string)$row['IN_REPLY_TO'] : null,
			'attachments' => array_map($this->mapAttachmentToWire(...), (array)($row['ATTACHMENTS'] ?? [])),
			'scheduledAt' => $this->formatAbsoluteTime($row['SCHEDULED_AT'] ?? null),
			'authorTimezoneId' => (string)($row['AUTHOR_TIMEZONE_ID'] ?? ''),
			'lastError' => is_array($row['LAST_ERROR'] ?? null) ? $row['LAST_ERROR'] : null,
			'errorReason' => ScheduledMessageErrorText::getReasonFromLastError($row['LAST_ERROR'] ?? null),
			'permissions' => [
				'canView' => true,
				'canManage' => $canManage,
			],
			'actions' => $actions,
		];
	}

	private function parseAbsoluteTime(string $value): ?DateTime
	{
		if (!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value))
		{
			$this->addError(new Error('Scheduled time must be an absolute ISO 8601 value.', ScheduledMessageService::ERROR_INVALID_TIME));

			return null;
		}

		try
		{
			$dateTime = new \DateTimeImmutable($value);

			return DateTime::createFromTimestamp($dateTime->getTimestamp());
		}
		catch (\Exception)
		{
			$this->addError(new Error('Scheduled time is invalid.', ScheduledMessageService::ERROR_INVALID_TIME));

			return null;
		}
	}

	private function formatAbsoluteTime(mixed $value): string
	{
		return $value instanceof DateTime ? $value->format(DATE_ATOM) : '';
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	/**
	 * The request names a Disk object of the author, not a file of the storage: the copy the letter is sent
	 * with is made by the service and gets its own `FILE_ID`.
	 *
	 * @param array<int, array<string, mixed>> $attachments
	 */
	private function mapAttachmentsToStorage(array $attachments): array
	{
		return array_map(static fn (array $attachment): array => [
			'OBJECT_ID' => (int)($attachment['objectId'] ?? 0),
			'FILE_NAME' => (string)($attachment['fileName'] ?? ''),
			'CONTENT_ID' => isset($attachment['contentId']) ? (string)$attachment['contentId'] : null,
			'IS_INLINE' => !empty($attachment['isInline']),
		], $attachments);
	}

	/** @param array<string, mixed> $attachment */
	private function mapAttachmentToWire(array $attachment): array
	{
		return [
			'id' => (int)($attachment['ID'] ?? 0),
			'fileId' => (int)($attachment['FILE_ID'] ?? 0),
			'objectId' => (int)($attachment['SOURCE_OBJECT_ID'] ?? 0) > 0
				? (int)$attachment['SOURCE_OBJECT_ID']
				: null,
			'fileName' => (string)($attachment['FILE_NAME'] ?? ''),
			'contentId' => isset($attachment['CONTENT_ID']) ? (string)$attachment['CONTENT_ID'] : null,
			'isInline' => ($attachment['IS_INLINE'] ?? false) === true || ($attachment['IS_INLINE'] ?? null) === 'Y',
		];
	}
}
