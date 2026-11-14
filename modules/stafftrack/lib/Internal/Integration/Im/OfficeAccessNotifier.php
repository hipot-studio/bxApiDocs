<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\StaffTrack\Provider\UserProvider;

/**
 * Notifications of a request to see the staff of an office: a confirmation with two buttons to every
 * decider and a plain notification of the outcome to the requester.
 *
 * The tag of a confirmation is unique per recipient, since the messenger removes every confirmation that
 * already carries the tag of a new one; the deciders of one revision of a request share the sub tag, and
 * the notifications of all of them are dropped by it at once.
 *
 * A failure of the messenger never leaves the notifier: it is logged, and a confirmation that failed is
 * not counted as sent.
 */
class OfficeAccessNotifier
{
	public const MODULE_ID = 'stafftrack';
	public const EVENT_REQUEST = 'office_access_request';
	public const EVENT_RESULT = 'office_access_result';

	public const RESULT_APPROVED = 'approved';
	public const RESULT_REJECTED = 'rejected';
	public const RESULT_OFFICE_DELETED = 'office_deleted';
	public const RESULT_ASSIGNED = 'assigned';

	public const BUTTON_APPROVE = 'Y';
	public const BUTTON_REJECT = 'N';

	private const TAG_PREFIX = 'STAFFTRACK|OFFICE_ACCESS|';
	private const TAG_PATTERN = '/^STAFFTRACK\|OFFICE_ACCESS\|([1-9]\d*)\|([1-9]\d*)\|[1-9]\d*$/';

	private const RESULT_MESSAGES = [
		self::RESULT_APPROVED => 'STAFFTRACK_INTEGRATION_IM_OFFICE_ACCESS_APPROVED',
		self::RESULT_REJECTED => 'STAFFTRACK_INTEGRATION_IM_OFFICE_ACCESS_REJECTED',
		self::RESULT_OFFICE_DELETED => 'STAFFTRACK_INTEGRATION_IM_OFFICE_ACCESS_OFFICE_DELETED',
		self::RESULT_ASSIGNED => 'STAFFTRACK_INTEGRATION_IM_OFFICE_ACCESS_ASSIGNED',
	];

	/**
	 * Returns whether notifications can be sent at all.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	/**
	 * Sends the confirmation of a request to every recipient, from the requester.
	 *
	 * @param array{id: int, officeId: int, userId: int, revision: int} $request
	 * @param string $officeTitle
	 * @param int[] $recipientIds
	 * @return int how many recipients the confirmation was delivered to
	 */
	public function sendRequest(array $request, string $officeTitle, array $recipientIds): int
	{
		if (!$this->isAvailable())
		{
			return 0;
		}

		$requesterId = (int)$request['userId'];
		$replacements = [
			'#USER_NAME#' => $this->toPlainText($this->getUserName($requesterId)),
			'#OFFICE_TITLE#' => $this->toPlainText($officeTitle),
		];
		$subTag = $this->buildSubTag((int)$request['id'], (int)$request['revision']);

		$sent = 0;
		foreach ($recipientIds as $recipientId)
		{
			$isSent = $this->send(__FUNCTION__, [
				'TO_USER_ID' => (int)$recipientId,
				'FROM_USER_ID' => $requesterId,
				'NOTIFY_TYPE' => IM_NOTIFY_CONFIRM,
				'NOTIFY_MODULE' => self::MODULE_ID,
				'NOTIFY_EVENT' => self::EVENT_REQUEST,
				'NOTIFY_TAG' => $subTag . '|' . (int)$recipientId,
				'NOTIFY_SUB_TAG' => $subTag,
				'NOTIFY_LINK' => $this->buildOfficeLink((int)$request['officeId']),
				'MESSAGE' => $this->localize('STAFFTRACK_INTEGRATION_IM_OFFICE_ACCESS_REQUEST', $replacements),
				'NOTIFY_BUTTONS' => $this->buildButtons(),
			]);

			if ($isSent)
			{
				$sent++;
			}
		}

		return $sent;
	}

	/**
	 * Sends a pressed confirmation back to whoever pressed it, for a press that decided nothing because the
	 * decision failed: the messenger removes the pressed confirmation whatever the answer is, and the request
	 * would wait for a decider left without the button. The text is the one the messenger stored for the
	 * decider, in their language, and the tag is the pressed one, so the confirmation takes its place. The
	 * stored fields hold no link, so the link to the card of the office is built anew; a confirmation whose
	 * office is unknown goes back without it.
	 *
	 * @param array $notify the fields of the pressed confirmation and RELATION_USER_ID of whoever pressed it,
	 *     as the messenger hands them to the handler of the press
	 * @param int $officeId the office of the request; zero when it is unknown
	 * @return bool whether the confirmation is back
	 */
	public function restoreRequest(array $notify, int $officeId = 0): bool
	{
		$request = self::parseTag((string)($notify['NOTIFY_TAG'] ?? ''));
		$recipientId = (int)($notify['RELATION_USER_ID'] ?? 0);
		$message = (string)($notify['MESSAGE'] ?? '');

		if ($request === null || $recipientId <= 0 || $message === '' || !$this->isAvailable())
		{
			return false;
		}

		$subTag = $this->buildSubTag($request['id'], $request['revision']);
		$fields = [
			'TO_USER_ID' => $recipientId,
			'FROM_USER_ID' => (int)($notify['AUTHOR_ID'] ?? 0),
			'NOTIFY_TYPE' => IM_NOTIFY_CONFIRM,
			'NOTIFY_MODULE' => self::MODULE_ID,
			'NOTIFY_EVENT' => self::EVENT_REQUEST,
			'NOTIFY_TAG' => $subTag . '|' . $recipientId,
			'NOTIFY_SUB_TAG' => $subTag,
			'MESSAGE' => $message,
			'NOTIFY_BUTTONS' => $this->buildButtons(),
		];

		if ($officeId > 0)
		{
			$fields['NOTIFY_LINK'] = $this->buildOfficeLink($officeId);
		}

		return $this->send(__FUNCTION__, $fields);
	}

	/**
	 * Drops the confirmations of one revision of a request from every recipient.
	 *
	 * @param int $id
	 * @param int $revision
	 * @return void
	 */
	public function dropRequest(int $id, int $revision): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		try
		{
			$this->deleteBySubTag($this->buildSubTag($id, $revision));
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
		}
	}

	/**
	 * Tells the requester the outcome of their request.
	 *
	 * @param string $result one of the RESULT_* constants
	 * @param int $requesterId
	 * @param int $officeId
	 * @param string $officeTitle
	 * @param int $fromUserId the decider; zero for an outcome nobody decided
	 * @return void
	 */
	public function sendResult(
		string $result,
		int $requesterId,
		int $officeId,
		string $officeTitle,
		int $fromUserId = 0,
	): void
	{
		$messageCode = self::RESULT_MESSAGES[$result] ?? null;
		if ($messageCode === null)
		{
			$this->logFailure(__FUNCTION__, 'unknown result ' . $result);

			return;
		}

		if (!$this->isAvailable())
		{
			return;
		}

		$this->send(__FUNCTION__, [
			'TO_USER_ID' => $requesterId,
			'FROM_USER_ID' => $fromUserId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => self::MODULE_ID,
			'NOTIFY_EVENT' => self::EVENT_RESULT,
			'NOTIFY_LINK' => $this->buildOfficeLink($officeId),
			'MESSAGE' => $this->localize($messageCode, ['#OFFICE_TITLE#' => $this->toPlainText($officeTitle)]),
		]);
	}

	/**
	 * Returns the answer shown to a decider who pressed a button of a request somebody had already decided.
	 *
	 * @return string
	 */
	public function getAlreadyDecidedText(): string
	{
		return (string)Loc::getMessage('STAFFTRACK_INTEGRATION_IM_OFFICE_ACCESS_ALREADY_DECIDED');
	}

	/**
	 * Reads the request and its revision back from the tag of a confirmation.
	 *
	 * @param string $tag
	 * @return array{id: int, revision: int}|null null for a tag of anything else
	 */
	public static function parseTag(string $tag): ?array
	{
		if (!preg_match(self::TAG_PATTERN, $tag, $matches))
		{
			return null;
		}

		return [
			'id' => (int)$matches[1],
			'revision' => (int)$matches[2],
		];
	}

	protected function add(array $fields): bool
	{
		return \CIMNotify::Add($fields) !== false;
	}

	protected function deleteBySubTag(string $subTag): void
	{
		\CIMNotify::DeleteBySubTag($subTag);
	}

	protected function getUserName(int $userId): string
	{
		return UserProvider::getInstance()->getNames([$userId])[$userId] ?? '';
	}

	protected function getSiteDir(): string
	{
		return defined('SITE_DIR') ? SITE_DIR : '/';
	}

	protected function logFailure(string $operation, string $reason): void
	{
		AddMessage2Log(static::class . '::' . $operation . ' failed: ' . $reason, 'stafftrack');
	}

	private function send(string $operation, array $fields): bool
	{
		try
		{
			return $this->add($fields);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($operation, $this->describeThrowable($exception));

			return false;
		}
	}

	/**
	 * The text is put together in the language of the recipient, which the messenger passes in.
	 */
	private function localize(string $code, array $replacements = []): \Closure
	{
		return static fn (?string $languageId = null): string => (string)Loc::getMessage(
			$code,
			$replacements,
			$languageId,
		);
	}

	/**
	 * A name or a title goes into the text as the person typed it, and the messenger reads the text as
	 * markup: angle brackets make tags, and square ones make its codes of links, pictures and mentions.
	 * On the way to its clients it turns &lt;, &gt;, &#91; and &#93; back into brackets, and its old web
	 * client puts the text into the page as it is. Escaping would not hold there, so the brackets of the
	 * value become look-alike ones: quotation marks for the angle brackets, full-width brackets for the
	 * square ones. A reference to a bracket in the value is escaped once more and reaches the clients as typed.
	 */
	private function toPlainText(string $value): string
	{
		return str_replace(
			['<', '>', '[', ']', '&lt;', '&gt;', '&#91;', '&#93;'],
			['‹', '›', '［', '］', '&amp;lt;', '&amp;gt;', '&amp;#91;', '&amp;#93;'],
			$value,
		);
	}

	private function buildButtons(): array
	{
		return [
			[
				'TITLE' => $this->localize('STAFFTRACK_INTEGRATION_IM_OFFICE_ACCESS_BUTTON_APPROVE'),
				'VALUE' => self::BUTTON_APPROVE,
				'TYPE' => 'accept',
			],
			[
				'TITLE' => $this->localize('STAFFTRACK_INTEGRATION_IM_OFFICE_ACCESS_BUTTON_REJECT'),
				'VALUE' => self::BUTTON_REJECT,
				'TYPE' => 'cancel',
			],
		];
	}

	private function buildSubTag(int $id, int $revision): string
	{
		return self::TAG_PREFIX . $id . '|' . $revision;
	}

	private function buildOfficeLink(int $officeId): string
	{
		return $this->getSiteDir() . 'stafftrack/offices/?office_id=' . $officeId;
	}

	/**
	 * The class of a failure and the frame it was raised in, never its message: a failure of the storage
	 * carries the query, and the query carries the text of the notification.
	 */
	private function describeThrowable(\Throwable $exception): string
	{
		return $exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine();
	}
}
