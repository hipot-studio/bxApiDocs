<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Helper\AnalyticsHelper;
use Bitrix\Mail\Helper\Message;
use Bitrix\Main\Localization\Loc;

/**
 * One row of the message list: the record the client draws the five cells from.
 *
 * Everything here is text, not markup - escaping belongs to the template that shows it. The
 * exceptions are the binding placeholders and the action handler strings, escaped by the server.
 */
class MessageRowBuilder
{
	private readonly BindingsPresenter $bindingsPresenter;
	private readonly RowActionsBuilder $actionsBuilder;
	private readonly array $availableSourceDirs;

	/**
	 * @param string $currentDir Folder of the filter; names the source of the slider and locks the
	 *                           permanent deletion.
	 * @param array $avatarConfigs Result of AvatarManager::getAvatarParamsFromEmails() for the page
	 * @param int $dateLastOpening Stamp of the previous opening of the list; anything newer is new
	 */
	public function __construct(
		private readonly ScreenContext $context,
		private readonly string $currentDir = '',
		private readonly array $avatarConfigs = [],
		private readonly int $dateLastOpening = 0,
		private readonly SenderPresenter $senderPresenter = new SenderPresenter(),
		private readonly DatePresenter $datePresenter = new DatePresenter(),
		private readonly AttachmentsPresenter $attachmentsPresenter = new AttachmentsPresenter(),
	)
	{
		$this->bindingsPresenter = new BindingsPresenter($context->crmActivityPermission);
		$this->actionsBuilder = new RowActionsBuilder($context, $currentDir);
		$this->availableSourceDirs = AnalyticsHelper::getAvailableDirsForAnalytics();
	}

	/**
	 * How a message opens, not where: one record for the whole page, since the source folder belongs
	 * to the list rather than to a letter. The address is added to the same record by the client, out
	 * of the template of the screen (MessageHrefTemplate).
	 *
	 * @return array{sliderData: array}
	 */
	public function buildSliderOptions(): array
	{
		$sliderData = ['printable' => true];
		if (in_array(mb_strtolower($this->currentDir), $this->availableSourceDirs, true))
		{
			$sliderData['data'] = ['analytics' => ['source_dir' => $this->currentDir]];
		}

		return ['sliderData' => $sliderData];
	}

	/**
	 * @param array $sliderOptions Result of buildSliderOptions(), the same record for every row of
	 *                             the page; the client fills the address into it
	 */
	public function build(array $item, DirectoryFlags $directory, array $sliderOptions): array
	{
		$rowId = $item['UID_ID'] . '-' . $item['MAILBOX_ID'];
		$messageId = (int)$item['MESSAGE_ID'];
		$timestamp = (int)makeTimeStamp($item['INTERNALDATE'] ?? $item['FIELD_DATE'] ?? '');
		$taskParams = $this->buildTaskParams($item, $timestamp);
		$postParams = $this->buildPostParams($item);

		return [
			'id' => $rowId,
			'messageId' => $messageId,
			'mailboxId' => (int)$item['MAILBOX_ID'],
			'dirMd5' => (string)($item['DIR_MD5'] ?? ''),
			'unseen' => !in_array((string)($item['IS_SEEN'] ?? ''), ['Y', 'S'], true),
			'isOld' => ($item['IS_OLD'] ?? null) === 'Y',
			'isNew' => $this->dateLastOpening <= $timestamp,
			'isSpam' => $directory->isSpam,
			'isFavorite' => !empty($item['__is_favorite']),
			'favoriteAvailable' => !$directory->isSpam && !$directory->isTrash,
			'sender' => $this->senderPresenter->present(
				$item,
				$this->context->mailboxEmail,
				$this->avatarConfigs,
			),
			'subject' => $this->resolveSubject($item),
			'date' => $this->datePresenter->present($timestamp),
			'attachments' => $this->attachmentsPresenter->present($item),
			'hasAttachments' => $this->attachmentsPresenter->hasAttachments($item),
			'bindings' => $this->bindingsPresenter->present($item, $taskParams, $postParams),
			'href' => $sliderOptions,
			'actions' => $this->actionsBuilder->build($rowId, $messageId, $taskParams, $postParams),
		];
	}

	private function resolveSubject(array $item): string
	{
		if (trim((string)($item['SUBJECT'] ?? '')) !== '')
		{
			return (string)$item['SUBJECT'];
		}

		$generated = Message::extractSubjectFromBody((string)($item['BODY'] ?? ''));

		return $generated !== ''
			? $generated
			: (string)Loc::getMessage('MAIL_MESSAGE_EMPTY_SUBJECT_PLACEHOLDER')
		;
	}

	/**
	 * What a new task out of this message is opened with. The parameters alone: the address they are
	 * added to is a setting of the page of the screen (RowHrefTemplates), and the client puts the two
	 * together.
	 *
	 * @return array<string, scalar>
	 */
	private function buildTaskParams(array $item, int $timestamp): array
	{
		return [
			'ta_sec' => 'mail',
			'ta_el' => 'context_menu',
			'TITLE' => (string)Loc::getMessage(
				'MAIL_MESSAGE_TASK_TITLE',
				['#SUBJECT#' => $item['SUBJECT'] ?? ''],
			),
			'UF_MAIL_MESSAGE' => (int)$item['MESSAGE_ID'],
			'MAIL_SUBJECT' => (string)($item['SUBJECT'] ?? ''),
			'MAIL_FROM' => (string)($item['FIELD_FROM'] ?? ''),
			'MAIL_DATE' => $timestamp,
		];
	}

	/**
	 * @return array<string, scalar>
	 */
	private function buildPostParams(array $item): array
	{
		return [
			'TITLE' => (string)Loc::getMessage(
				'MAIL_MESSAGE_POST_TITLE',
				['#SUBJECT#' => $item['SUBJECT'] ?? ''],
			),
			'UF_MAIL_MESSAGE' => (int)$item['MESSAGE_ID'],
		];
	}
}
