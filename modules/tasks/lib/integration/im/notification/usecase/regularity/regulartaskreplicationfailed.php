<?php

namespace Bitrix\Tasks\Integration\IM\Notification\UseCase\Regularity;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Integration\IM;
use Bitrix\Tasks\Internals\Notification\EntityOperation;
use Bitrix\Tasks\Slider\Path\PathMaker;
use Bitrix\Tasks\Slider\Path\TemplatePathMaker;

/**
 * Personal, template-bound notification to the template author when an after_completion instance
 * could not be created (AC-T-020 / ERR-T-006). Generic — no ERR-S classification (Part II) — and
 * gated by the release flag at the call site.
 *
 * Sent directly through {@see IM::notifyAdd} instead of the task-centric
 * {@see \Bitrix\Tasks\Integration\IM\Notification\Provider} pipeline: the failed instance has no
 * task object to bind a Message to (that pipeline dereferences the task unconditionally).
 */
final class RegularTaskReplicationFailed
{
	// Per-template tag: im replaces an earlier notification carrying the same tag+recipient, so a
	// shared constant would collapse failures of different regular tasks into one (the template
	// author would see only the latest). The templateId keeps one live notification PER template.
	private const NOTIFY_TAG_PREFIX = 'TASKS|REGULAR_REPLICATION_FAILED';

	public function send(int $recipientId, int $templateId, string $templateTitle = ''): void
	{
		if ($recipientId <= 0 || !Loader::includeModule('im'))
		{
			return;
		}

		Loc::loadMessages(__FILE__);

		// In-app notification carries a BBCode link to the template ([URL=...] — as Notification\Task\Title
		// does for tasks); the "out" channels (email/push) get the plain title, so no raw BBCode leaks there.
		$linkedMessage = $this->buildMessage($this->buildTitleLink($recipientId, $templateId, $templateTitle));
		if ($linkedMessage === '')
		{
			return;
		}

		$plainMessage = $this->buildMessage($templateTitle);

		IM::notifyAdd([
			'TO_USER_ID' => $recipientId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'tasks',
			'NOTIFY_EVENT' => EntityOperation::REGULAR_REPLICATION_FAILED,
			'NOTIFY_TAG' => self::NOTIFY_TAG_PREFIX . '|' . $templateId,
			'NOTIFY_MESSAGE' => $linkedMessage,
			'NOTIFY_MESSAGE_OUT' => $plainMessage,
		]);
	}

	private function buildMessage(string $title): string
	{
		return (string)Loc::getMessage(
			'TASKS_REGULAR_TASK_REPLICATION_FAILED',
			['#TASK_TITLE#' => $title],
		);
	}

	private function buildTitleLink(int $recipientId, int $templateId, string $templateTitle): string
	{
		if ($templateId <= 0)
		{
			return $templateTitle;
		}

		$url = (new TemplatePathMaker($templateId, PathMaker::DEFAULT_ACTION, $recipientId))->makeEntityPath();

		return '[URL=' . $url . ']' . $templateTitle . '[/URL]';
	}
}
