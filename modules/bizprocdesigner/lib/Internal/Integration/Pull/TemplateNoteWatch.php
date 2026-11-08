<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\Pull;

use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum\BizprocDesignerPullEvent;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum\TemplateNoteAction;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;

/**
 * EVENT-01. The live channel of the note discussions of one template: everybody who holds the scheme
 * open watches the same tag, so a change made by one editor reaches the others without their asking.
 *
 * The tag is built here out of the identifier of the template and is never accepted from a client: a tag
 * arriving from the outside would let a stranger listen to the discussions of a foreign template. The
 * subscription is handed out only where the right to the template has already been checked - the reading
 * of the scheme and the reading of the notes.
 *
 * The signal carries three numbers and nothing else: no text and no author. It says that something in the
 * discussions of the template has changed, and the editor asks for what exactly.
 *
 * The module of the live updates is an optional one, and the whole feature is behind a flag: without
 * either of them the signal is silently dropped and the operation that caused it stands.
 */
class TemplateNoteWatch
{
	private const TAG_PREFIX = 'BIZPROC_TEMPLATE_NOTES_';

	/**
	 * @param int $userId the current user, whose right to the template the caller has already checked
	 */
	public function subscribe(int $templateId, int $userId): void
	{
		if ($userId <= 0 || !$this->isAvailable($templateId))
		{
			return;
		}

		$this->addWatch($userId, self::tag($templateId));
	}

	public function notify(int $templateId, int $noteId, TemplateNoteAction $action): void
	{
		if ($noteId <= 0 || !$this->isAvailable($templateId))
		{
			return;
		}

		$this->addToStack(self::tag($templateId), [
			'module_id' => BizprocDesignerPullManager::MODULE_ID,
			'command' => BizprocDesignerPullEvent::TemplateNoteUpdated->value,
			'params' => [
				'templateId' => $templateId,
				'noteId' => $noteId,
				'action' => $action->value,
			],
		]);
	}

	protected function isPullModuleAvailable(): bool
	{
		try
		{
			return Loader::includeModule('pull');
		}
		catch (LoaderException)
		{
			return false;
		}
	}

	protected function addWatch(int $userId, string $tag): void
	{
		\CPullWatch::Add($userId, $tag);
	}

	protected function addToStack(string $tag, array $event): void
	{
		\CPullWatch::AddToStack($tag, $event);
	}

	/**
	 * Switching the feature off stops the signals as well, not only the interface: an editor left with an
	 * open scheme must not keep receiving events of a feature that has been rolled back.
	 */
	private function isAvailable(int $templateId): bool
	{
		return $templateId > 0
			&& Feature::instance()->areTemplateNotesAvailable()
			&& $this->isPullModuleAvailable()
		;
	}

	/**
	 * The subscription and the signal have to produce the same string, or the signal reaches nobody.
	 */
	private static function tag(int $templateId): string
	{
		return self::TAG_PREFIX . $templateId;
	}
}
