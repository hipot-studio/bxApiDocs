<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Helper\AnalyticsHelper;

/**
 * Where a row leads outside the mail section: the task, the post and the event a message is bound to
 * or may be made into.
 *
 * The addresses are built out of the PATH_TO_USER_* parameters the complex component is included
 * with (mail/install/components/bitrix/mail.client/class.php:42), which only the page of the mail
 * section knows. A portal is free to pass its own, so the same rule as for the address of a message
 * holds here: the screen sends the templates once, every page carries identifiers and parameters,
 * and the client puts the two together. The controller has no access to those parameters and would
 * have to guess them - and a guess makes the rows after "show more" lead somewhere else than the
 * rows the screen drew.
 */
final class RowHrefTemplates
{
	/** The client knows the same word (`row-href.ts`). */
	public const ID_PLACEHOLDER = '__ENTITY_ID__';

	public const TASK_CREATE = 'taskCreate';
	public const TASK_VIEW = 'taskView';
	public const POST_CREATE = 'postCreate';
	public const POST_VIEW = 'postView';
	public const CALENDAR_EVENT = 'calendarEvent';

	public function __construct(
		public readonly string $taskCreate = '',
		public readonly string $taskView = '',
		public readonly string $postCreate = '',
		public readonly string $postView = '',
		public readonly string $calendarEvent = '',
	)
	{
	}

	/**
	 * The analytics of the view of a task belong to the template rather than to a row: they are the
	 * same for every message of the list.
	 */
	public static function fromPaths(ScreenPaths $paths): self
	{
		return new self(
			taskCreate: \CComponentEngine::makePathFromTemplate(
				$paths->task,
				['action' => 'edit', 'task_id' => '0'],
			),
			taskView: AnalyticsHelper::addAnalyticsToMessage(
				\CComponentEngine::makePathFromTemplate(
					$paths->task,
					['action' => 'view', 'task_id' => self::ID_PLACEHOLDER],
				),
				['ta_sec' => 'mail', 'ta_el' => 'view_button'],
			),
			postCreate: \CComponentEngine::makePathFromTemplate(
				$paths->blogPostEdit,
				['post_id' => '0'],
			),
			postView: \CComponentEngine::makePathFromTemplate(
				$paths->blogPost,
				['post_id' => self::ID_PLACEHOLDER],
			),
			calendarEvent: \CComponentEngine::makePathFromTemplate(
				$paths->calendarEvent,
				['event_id' => self::ID_PLACEHOLDER],
			),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array
	{
		return [
			self::TASK_CREATE => $this->taskCreate,
			self::TASK_VIEW => $this->taskView,
			self::POST_CREATE => $this->postCreate,
			self::POST_VIEW => $this->postView,
			self::CALENDAR_EVENT => $this->calendarEvent,
		];
	}
}
