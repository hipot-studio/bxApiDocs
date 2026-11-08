<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Main\Localization\Loc;

/** One row of the draft list: a draft names its recipients where a message names its sender. */
class DraftRowBuilder
{
	private readonly array $actions;

	public function __construct(
		private readonly DatePresenter $datePresenter = new DatePresenter(),
	)
	{
		$this->actions = GridActionsCatalog::getAll();
	}

	/**
	 * @return array|null null for an item without an id of its own
	 */
	public function build(array $item): ?array
	{
		$draftId = (int)($item['id'] ?? 0);
		if ($draftId <= 0)
		{
			return null;
		}

		$subject = trim((string)($item['subject'] ?? ''));

		return [
			'id' => 'draft-' . $draftId,
			'draftId' => $draftId,
			'recipients' => $this->presentRecipients($item),
			'subject' => $subject !== ''
				? $subject
				: (string)Loc::getMessage('MAIL_MESSAGE_EMPTY_SUBJECT_PLACEHOLDER'),
			'preview' => trim((string)($item['bodyPreview'] ?? '')),
			'hasAttachments' => (int)($item['attachmentCount'] ?? 0) > 0,
			'date' => $this->datePresenter->presentWithoutTimestamp($this->resolveUpdatedAt($item)),
			'actions' => [
				$this->buildAction('view', "openDraft({$draftId})"),
				$this->buildAction('delete', "confirmDeleteDraft({$draftId})"),
			],
		];
	}

	/**
	 * @return array{names: array<int, string>, isEmpty: bool}
	 */
	private function presentRecipients(array $item): array
	{
		$names = [];

		foreach ((array)($item['recipients'] ?? []) as $recipient)
		{
			$recipient = (array)$recipient;
			$displayText = (string)(($recipient['name'] ?? '') ?: ($recipient['email'] ?? ''));
			if ($displayText !== '')
			{
				$names[] = $displayText;
			}
		}

		return ['names' => $names, 'isEmpty' => $names === []];
	}

	/** updatedAt arrives as DATE_ATOM, shift it to user time like the message rows do. */
	private function resolveUpdatedAt(array $item): ?int
	{
		$updatedAt = strtotime((string)($item['updatedAt'] ?? ''));

		return $updatedAt === false ? null : $updatedAt + \CTimeZone::getOffset();
	}

	private function buildAction(string $key, string $call): array
	{
		$action = $this->actions[$key];

		return [
			'id' => $action['id'],
			'icon' => $action['icon'],
			'text' => $action['text'],
			'title' => $action['title'],
			'onclick' => "BX.Mail.Client.Message.List['"
				. \CUtil::jsEscape(ScreenContext::LIST_MANAGER_ID)
				. "']." . $call,
		];
	}
}
