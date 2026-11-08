<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Intranet;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Service\UndoSend\UndoSendCancelToken;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageService;
use Bitrix\Mail\Service\UndoSend\UndoSendPreference;
use Bitrix\Mail\Service\UndoSend\UndoSendService;
use Bitrix\Main;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class UndoSend extends Controller
{
	public const ERROR_FEATURE_UNAVAILABLE = 'feature_unavailable';

	/**
	 * The cancellation runs without a session: its window is seconds long, and an authorized request
	 * would queue behind the session lock of the tab that is still sending. The client asks for it with
	 * `credentials: 'omit'`, so the session filters have nothing left to check and come off. What
	 * authorizes the call instead is the signed token of {@see UndoSendCancelToken}, and the owner of
	 * the row is verified anew under a row lock by the repository behind `cancel()`.
	 */
	public function configureActions(): array
	{
		return [
			'cancelByToken' => [
				'-prefilters' => [
					Main\Engine\ActionFilter\Authentication::class,
					Main\Engine\ActionFilter\Csrf::class,
					Intranet\ActionFilter\IntranetUser::class,
				],
			],
		];
	}

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
			new Main\Engine\ActionFilter\CloseSession(),
		];
		if (Loader::includeModule('intranet'))
		{
			$filters[] = new Intranet\ActionFilter\IntranetUser();
		}

		return $filters;
	}

	public function createAction(
		UndoSendService $service,
		int $mailboxId,
		array $sender,
		array $recipients,
		string $subject,
		string $body,
		string $timezoneId,
		string $idempotencyKey,
		array $attachments = [],
		array $largeAttachments = [],
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

		$response = $this->resultResponse($service->create(
			$this->getUserId(),
			[
				'MAILBOX_ID' => $mailboxId,
				'SENDER' => $sender,
				'RECIPIENTS' => $recipients,
				'SUBJECT' => $subject,
				'BODY' => $body,
				'ATTACHMENTS' => $this->mapAttachmentsToStorage($attachments),
				'LARGE_ATTACHMENTS' => $largeAttachments,
				'AUTHOR_TIMEZONE_ID' => $timezoneId,
				'IN_REPLY_TO' => $inReplyTo,
				...($saveAsTemplate ? [ScheduledMessageService::SAVE_AS_TEMPLATE_KEY => true] : []),
			],
			$idempotencyKey,
			$draftId,
			$draftRevision,
		));

		return $response;
	}

	public function cancelAction(UndoSendService $service, int $id, int $revision): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		return $this->resultResponse($service->cancel($this->getUserId(), $id, $revision));
	}

	public function cancelByTokenAction(
		UndoSendService $service,
		UndoSendCancelToken $cancelToken,
		string $token,
	): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		$tokenResult = $cancelToken->verify($token);
		if (!$tokenResult->isSuccess())
		{
			$this->addErrors($tokenResult->getErrors());

			return [];
		}

		$tokenData = $tokenResult->getData();

		return $this->resultResponse($service->cancel(
			(int)$tokenData['userId'],
			(int)$tokenData['operationId'],
			(int)$tokenData['revision'],
		));
	}

	public function getPreferenceAction(UndoSendPreference $preference): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		return ['delaySeconds' => $preference->get($this->getUserId())];
	}

	public function setPreferenceAction(UndoSendPreference $preference, int $delaySeconds): array
	{
		if (!$this->ensureFeatureAvailable())
		{
			return [];
		}

		return $this->resultResponse($preference->set($this->getUserId(), $delaySeconds));
	}

	protected function isFeatureAvailable(): bool
	{
		return Feature::isUndoSendAvailable();
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

	private function resultResponse(Result $result): array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	/** @param array<int, array<string, mixed>> $attachments */
	private function mapAttachmentsToStorage(array $attachments): array
	{
		return array_map(static fn (array $attachment): array => [
			'OBJECT_ID' => (int)($attachment['objectId'] ?? 0),
			'FILE_NAME' => (string)($attachment['fileName'] ?? ''),
			'CONTENT_ID' => isset($attachment['contentId']) ? (string)$attachment['contentId'] : null,
			'IS_INLINE' => !empty($attachment['isInline']),
		], $attachments);
	}
}
