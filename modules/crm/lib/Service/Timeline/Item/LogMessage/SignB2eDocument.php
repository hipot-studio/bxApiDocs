<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage;

use Bitrix\Crm\Integration\Tasks\TaskAccessController;
use Bitrix\Crm\Integration\Tasks\TaskObject;
use Bitrix\Crm\Integration\Tasks\TaskPathMaker;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\Model;
use Bitrix\Crm\Service\Timeline\Layout\Action\Redirect;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Crm\Service\Timeline\Item\LogMessage;
use Bitrix\Crm\Settings\Crm;
use Bitrix\Crm\Timeline;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Timeline\SignDocument\DocumentData;
use Bitrix\Crm\Timeline\SignDocument\MessageData;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\Member\Role;

class SignB2eDocument extends LogMessage
{
	protected ?DocumentData $documentData = null;
	protected ?MessageData $messageData = null;
	protected ?Document $signDocument = null;
	private ?Member $member = null;
	private ?MemberService $memberService = null;

	public function __construct(Context $context, Model $model)
	{
		if (Loader::includeModule('sign'))
		{
			if (method_exists(
				'\Bitrix\Sign\Service\Cache\Memory\Sign\MemberService',
				'getUserRepresentedName'
			))
			{
				$this->memberService = new \Bitrix\Sign\Service\Cache\Memory\Sign\MemberService();
			}
		}

		parent::__construct($context, $model);
	}

	public function getType(): string
	{
		return 'SignB2eDocumentLog';
	}

	public static function isActive(): bool
	{
		return Crm::isDocumentSigningEnabled();
	}

	public function getIconCode(): ?string
	{
		return match ($this->model->getTypeCategoryId())
		{
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CREATED => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_STOPPED => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_DONE => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_RESPONSIBILITY_PERSON => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_EMPLOYEE => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_REVIEWER => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_EDITOR => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_RESPONSIBILITY_PERSON => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_EMPLOYEE => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_REVIEWER => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_EDITOR => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MESSAGE_DELIVERED => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_SIGNED_DELIVERED => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MESSAGE_DELIVERY_ERROR => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MESSAGE_SENT => $this->getChannelIcon(),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_SIGNING_EXPIRED => Icon::ATTENTION,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_SNILS_ERROR => Icon::ATTENTION,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_SIGNING_ERROR => Icon::ATTENTION,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_DOCUMENT_PREPARATION_FAILED => Icon::ATTENTION,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_STOPPED_BY_ASSIGNEE => $this->getChannelIcon(),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_STOPPED_BY_REVIEWER => $this->getChannelIcon(),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_STOPPED_BY_EDITOR => $this->getChannelIcon(),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CONFIGURATION_ERROR => Icon::ATTENTION,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULLED => Icon::DOCUMENT,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULMENT_CANCELED => Icon::DOCUMENT,
			default => Icon::INFO,
		};
	}

	public function getTitle(): ?string
	{
		return match ($this->model->getTypeCategoryId())
		{
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CREATED => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_CREATE_TITLE_MSG_1'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_STOPPED,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_RESPONSIBILITY_PERSON,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_REVIEWER,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_EDITOR => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_STOPPED_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_DONE => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DONE_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_RESPONSIBILITY_PERSON => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_RESPONSIBILITY_SIGN_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_EMPLOYEE => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EMPLOYEE_SIGN_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_REVIEWER => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_REVIEWER_SIGN_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_EDITOR => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EDITOR_SIGN_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_EMPLOYEE => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EMPLOYEE_CANCEL_TITLE_MSG_1'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MESSAGE_SENT => $this->getMessageSentTitle(),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MESSAGE_DELIVERED => $this->getMessageDelivered(),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_SIGNED_DELIVERED => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EMPLOYEE_SIGN_DELIVERED_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MESSAGE_DELIVERY_ERROR => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_ERROR'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_SIGNING_EXPIRED => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_MEMBER_SIGNING_EXPIRED'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_SNILS_ERROR => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_MEMBER_SNILS_ERROR'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_SIGNING_ERROR => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_MEMBER_SIGNING_ERROR'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_DOCUMENT_PREPARATION_FAILED => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_PREPARATION_FAILED'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_STOPPED_BY_ASSIGNEE,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_STOPPED_BY_REVIEWER,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_STOPPED_BY_EDITOR => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EMPLOYEE_STOPPED'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CONFIGURATION_ERROR => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_CONFIGURE_ERROR_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULLED => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_ANNULLED_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULMENT_CANCELED => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_ANNULMENT_CANCELED_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ACCEPTED_AUTOMATICALLY => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_ACCEPTED_AUTOMATICALLY_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_APPROVED_BY_REPRESENTATIVE => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_APPROVED_BY_REPRESENTATIVE_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_REPRESENTATIVE => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_CANCELED_BY_REPRESENTATIVE_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_PROCESSING_TASK_CREATED => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_PROCESSING_TASK_CREATED_TITLE'),
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_PROCESSING_TASK_FAILED => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_PROCESSING_TASK_FAILED_TITLE'),
			default => null,
		};
	}

	public function getContentBlocks(): ?array
	{
		$blocks = [];

		$document = $this->loadSignDocument();
		if (!$document)
		{
			return $blocks;
		}

		if ($this->model->getTypeCategoryId() === Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CONFIGURATION_ERROR)
		{
			return array_filter([
				'error' => $this->getErrorContentBlock()
			]);
		}

		if ($this->isNeedDocumentNameBlock())
		{
			$blocks['doc'] = $this->getDocumentTitleBlock();
		}

		if (
			$this->model->getTypeCategoryId() === Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CREATED
			&& !empty($document->uid)
		)
		{
			$blocks['uid'] = $this->getDocumentUidBlock($document->uid);
		}

		$blocks += $this->getDocumentMessageBlocks($document);

		if ($this->model->getTypeCategoryId() === Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_RESPONSIBILITY_PERSON)
		{
			$blocks['responsible'] = $this->getResponsiblePersonBlock($document->representativeId);
		}

		$member = $this->getMember();
		if ($member && !$this->isAnnulmentEvent())
		{
			$blocks['employee'] = match ($member->role)
			{
				Role::ASSIGNEE => $this->getResponsiblePersonBlock($document->representativeId),
				Role::SIGNER => $this->getEmployeePersonBlock($member->entityId),
				Role::REVIEWER => $this->getReviewerBlock($member->entityId),
				Role::EDITOR => $this->getEditorBlock($member->entityId),
			};
			$blocks += $this->getSesSignBlocks($member->entityId);
		}

		if ($this->isAnnulmentEvent())
		{
			$blocks['annulledEmployee'] = $this->getAnnulledEmployeeBlock();
			$blocks['annulledCount'] = $this->getAnnulledMembersCountBlock();
		}

		$goskeyOrderId = $this->loadMessageData()?->getGoskeyOrderId();
		if ($goskeyOrderId !== null)
		{
			$blocks['provider'] = $this->getProviderChannelBlock(
				Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_CHANNEL_GOSKEY')
			);
			$blocks['goskeyOrderId'] = $this->getGoskeyOrderIdBlock($goskeyOrderId);
		}

		if ($this->isNeedChannel())
		{
			$blocks['channel'] = $this->getMessageChannelBlock();
		}
		if ($this->loadMessageData()?->getProviderName() && $this->isNeedProvider())
		{
			$blocks['provider'] = $this->getProviderChannelBlock(
				$this->getProviderNameByCode($this->loadMessageData()?->getProviderName())
			);
		}

		if ($this->loadDocumentData()->getInitiatorUserId() && $this->isStoppedByInitiator())
		{
			$blocks['initiator'] = $this->getStopInitiatorBlock();
		}

		if ($this->loadDocumentData()->getInitiatorUserId() && $this->isAnnulmentEvent())
		{
			$blocks['initiator'] = $this->getAnnulmentInitiatorBlock();
		}

		// Like the invitation message, the tails are shown in the timeline of the document itself
		// only: the record is bound to other cards too, and their readers may not read the document.
		if (
			$this->model->getTypeCategoryId() === Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CANCELED_BY_REPRESENTATIVE
			&& $this->isItemAboutCurrentEntity()
		)
		{
			$blocks['cancelReason'] = $this->getCancelReasonBlock();
		}

		if (
			$this->model->getTypeCategoryId() === Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_PROCESSING_TASK_CREATED
			&& $this->isItemAboutCurrentEntity()
		)
		{
			$blocks['processingTask'] = $this->getProcessingTaskBlock();
		}

		return array_filter($blocks);
	}

	/**
	 * Reason a representative gave while cancelling the document. A person types it, so it is
	 * rendered the way the message attached to an invitation is: as plain text.
	 *
	 * An entry with no reason stored keeps the record without the block - the verdict itself is
	 * in the title and stands on its own.
	 */
	private function getCancelReasonBlock(): ?Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		$reason = trim((string)$this->loadDocumentData()->getCancelReason());
		if ($reason === '')
		{
			return null;
		}

		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setWordWrap()
			->setFixedWidth(false)
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_CANCEL_REASON_TITLE'))
			->setContentBlock(
				(new Layout\Body\ContentBlock\Text())
					->setValue($this->preserveTypedText($reason))
					->setIsMultiline()
			)
		;
	}

	/**
	 * Task the record points at. The title is the one stored with the entry, the address is
	 * resolved against the task itself - so a task without an address leaves the title as text
	 * instead of a link that leads nowhere. A reader who may not read the task, a task that is gone
	 * and a portal without the tasks module get the fact alone, without the title.
	 *
	 * A document has one such record, and the block is built in its own timeline only, so the task
	 * is read once per card.
	 */
	private function getProcessingTaskBlock(): ?Layout\Body\ContentBlock
	{
		$documentData = $this->loadDocumentData();
		$title = trim((string)$documentData->getProcessingTaskTitle());
		$taskId = (int)$documentData->getProcessingTaskId();
		if ($title === '' || $taskId <= 0)
		{
			return null;
		}

		if (!$this->canReadProcessingTask($taskId))
		{
			return (new Layout\Body\ContentBlock\Text())
				->setValue(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_PROCESSING_TASK_NO_ACCESS'))
			;
		}

		$path = $this->getProcessingTaskPath($taskId);
		if ($path === null)
		{
			return (new Layout\Body\ContentBlock\Text())->setValue($title);
		}

		return (new Layout\Body\ContentBlock\Link())
			->setValue($title)
			->setAction(new Redirect(new Uri($path)))
		;
	}

	protected function canReadProcessingTask(int $taskId): bool
	{
		return TaskAccessController::canRead($taskId, $this->getContext()->getUserId());
	}

	/**
	 * TaskPathMaker reads the task to choose between the group and the personal context, and a
	 * task that is no longer there takes it down with itself, so it is asked for first.
	 */
	protected function getProcessingTaskPath(int $taskId): ?string
	{
		if (TaskObject::getObject($taskId) === null)
		{
			return null;
		}

		return TaskPathMaker::getPathMaker($taskId, $this->getContext()->getUserId())?->makeEntityPath();
	}

	private function getGoskeyOrderIdBlock(string $goskeyOrderId): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline()
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_GOSKEY_ORDER_ID'))
			->setContentBlock(
				(new Layout\Body\ContentBlock\Text())
					->setValue($goskeyOrderId)
			)
		;
	}

	private function getDocumentTitleBlock(): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline()
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_BLOCK_NAME_TITLE'))
			->setFixedWidth(false)
			->setContentBlock(
				(new Layout\Body\ContentBlock\Text())
					->setValue($this->getAssociatedEntityModel()->get('TITLE'))
			)
		;
	}

	private function getDocumentUidBlock(string $uid): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline()
			->setFixedWidth(false)
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_UID_TITLE'))
			->setContentBlock(
				(new Layout\Body\ContentBlock\Text())
					->setValue($uid)
			)
		;
	}

	/**
	 * Message the company attached to the signing invitation, together with whoever sent it.
	 *
	 * The text is a single attribute of the document, so it is rendered on the document creation
	 * entry, which the current B2E flow writes once per document. The message sent entry repeats
	 * for every member and would show the same text as many times as there are signers.
	 *
	 * Read from the document instead of the entry: the text is written while the document is still
	 * editable, which is after the entry has been stored.
	 *
	 * Shown in the timeline of the document itself only. The entry is also bound to the client and
	 * the own company of the item, so on those timelines it is visible to whoever reads the parent
	 * rather than the document, and the text is written for the signers.
	 *
	 * @return array<string, Layout\Body\ContentBlock\ContentBlockWithTitle>
	 */
	private function getDocumentMessageBlocks(Document $document): array
	{
		if (
			$this->model->getTypeCategoryId() !== Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CREATED
			|| !$this->isItemAboutCurrentEntity()
		)
		{
			return [];
		}

		if (!$this->isDocumentMessageEnabled())
		{
			return [];
		}

		// The property arrives with the same sign feature as the flag, so it is read after the check.
		$message = (string)$document->inviteMessage;
		if ($message === '')
		{
			return [];
		}

		$blocks = ['documentMessage' => $this->getDocumentMessageBlock($message)];
		if ($document->createdById)
		{
			$blocks['documentMessageSender'] = $this->getDocumentMessageSenderBlock($document->createdById);
		}

		return $blocks;
	}

	protected function isDocumentMessageEnabled(): bool
	{
		if (!Loader::includeModule('sign'))
		{
			return false;
		}

		return method_exists(Feature::class, 'isDocumentMessageEnabled')
			&& Feature::instance()->isDocumentMessageEnabled();
	}

	/**
	 * The value is typed by a user, so it is rendered as plain text: the text block encodes it
	 * before it reaches the markup, unlike the html string and chat message blocks.
	 */
	private function getDocumentMessageBlock(string $message): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setWordWrap()
			->setFixedWidth(false)
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_MESSAGE_TITLE'))
			->setContentBlock(
				(new Layout\Body\ContentBlock\Text())
					->setValue($this->preserveTypedText($message))
					->setIsMultiline()
			)
		;
	}

	/**
	 * The text block decodes html entities while it serializes itself, so an entity a user typed
	 * literally would reach the page as markup. That block is shared by every timeline record and
	 * is fixed under task 737138, hence the local pre-encoding: the decoding gives the text back
	 * unchanged, and the probe drops the compensation as soon as the block stops decoding.
	 */
	private function preserveTypedText(string $message): string
	{
		static $isDecodedOnSerialization = null;
		if ($isDecodedOnSerialization === null)
		{
			$probe = (new Layout\Body\ContentBlock\Text())->setValue('&lt;');
			$isDecodedOnSerialization = (($probe->toArray()['properties']['value'] ?? null) === '<');
		}

		return $isDecodedOnSerialization
			? htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			: $message
		;
	}

	private function getDocumentMessageSenderBlock(int $userId): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setFixedWidth(false)
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_MESSAGE_SENDER_TITLE'))
			->setContentBlock($this->getUserNameLink($userId))
		;
	}

	protected function loadDocumentData(): DocumentData
	{
		if (!$this->documentData)
		{
			$this->documentData = DocumentData::createFromArray(
				$this->getHistoryItemModel()->get(
					Timeline\HistoryDataModel\Presenter\SignDocument::DOCUMENT_DATA_KEY
				)
			);
		}

		return $this->documentData;
	}

	protected function loadSignDocument(): ?Document
	{
		if (!$this->documentData)
		{
			$this->loadDocumentData();
		}

		if (!$this->signDocument)
		{
			$this->signDocument = Container::instance()->getDocumentRepository()->getById($this->documentData->getDocumentId());
		}

		return $this->signDocument;
	}

	protected function loadMessageData(): ?MessageData
	{
		if (!$this->messageData)
		{
			$data = $this->getHistoryItemModel()->get(
				Timeline\HistoryDataModel\Presenter\SignDocument::MESSAGE_DATA_KEY
			);

			if (!empty($data))
			{
				$this->messageData = MessageData::createFromArray($data);
			}
		}

		return $this->messageData;
	}

	private function getEditorBlock(int $userId): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setFixedWidth(false)
			->setTitle($this->getEditorBlockTitle())
			->setContentBlock($this->getUserNameLink($userId))
		;
	}

	private function getReviewerBlock(int $userId): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setFixedWidth(false)
			->setTitle($this->getReviewerBlockTitle())
			->setContentBlock($this->getUserNameLink($userId))
		;
	}

	private function getResponsiblePersonBlock(int $userId): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setFixedWidth(false)
			->setTitle($this->getResponsibleBlockTitle())
			->setContentBlock($this->getUserNameLink($userId))
		;
	}

	private function getEmployeePersonBlock(int $userId): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setFixedWidth(false)
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EMPLOYEE'))
			->setContentBlock($this->getUserNameLink($userId))
		;
	}

	private function getUserNameLink(int $userId): Layout\Body\ContentBlock\Link
	{
		$userData = $this->getUserData($userId);
		$name = $this->memberService !== null
			? $this->memberService->getUserRepresentedName($userId)
			: $userData['FORMATTED_NAME'] ?? null
		;

		return (new Layout\Body\ContentBlock\Link())
			->setValue($name)
			->setAction(new Redirect(new Uri($userData['SHOW_URL'] ?? '')))
		;
	}

	private function getProviderChannelBlock(?string $channelName): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return
			(new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline()
			->setFixedWidth(false)
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_CHANNEL_MSG_1'))
			->setContentBlock(
				(new Layout\Body\ContentBlock\Text())
					->setValue($channelName)
			)
		;
	}

	private function getSesSignBlock(string $signature): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		return
			(new Layout\Body\ContentBlock\ContentBlockWithTitle())
				->setInline()
				->setFixedWidth(false)
				->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_BLOCK_SES_SIGN_TITLE'))
				->setContentBlock(
					(new Layout\Body\ContentBlock\Text())
						->setValue($signature)
				)
		;
	}

	private function getSesSignBuildBlock(string $username, int $userId): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		$title = Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_BLOCK_SES_BUILD_TITLE');
		$userData = $this->getUserData($userId);
		$url = $userData['SHOW_URL'] ?? '';
		$usernameWithLink = $this->isUrl($url) ? '<a href="' . $url . '">' . $username . '</a>' : $url;
		$description = Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_BLOCK_SES_BUILD_DESCRIPTION', [
			'#USERNAME#' => $usernameWithLink,
		]);

		return
			(new Layout\Body\ContentBlock\ContentBlockWithTitle())
				->setInline()
				->setFixedWidth(false)
				->setTitle($title)
				->setContentBlock(Layout\Body\ContentBlock\ContentBlockFactory::createFromHtmlString($description))
		;
	}

	private function isUrl(string $url): bool
	{
		return preg_match('#^(?:/|https?://)#', $url);
	}

	/**
	 * @return array<string, Layout\Body\ContentBlock\ContentBlockWithTitle>
	 */
	private function getSesSignBlocks(int $userId): array
	{
		if (!$this->loadMessageData() || !$this->messageData->getSesUsername())
		{
			return [];
		}

		$channelName = Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_CHANNEL_SES');

		return [
			'provider' => $this->getProviderChannelBlock($channelName),
			'sesSignBuild' => $this->getSesSignBuildBlock($this->messageData->getSesUsername(), $userId),
		];
	}

	private function getMember(): ?Member
	{
		if (!isset($this->member))
		{
			$document = $this->loadSignDocument();
			if (!$document)
			{
				return null;
			}
			$memberHash = $this->documentData?->getMemberHash();
			if (!$memberHash)
			{
				return null;
			}

			$this->member = Container::instance()->getMemberRepository()->getByUid($memberHash);
		}

		return $this->member;
	}

	private function getMessageSentTitle(): ?string
	{
		return match ($this->getMember()?->role)
		{
			Role::ASSIGNEE => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_TO_ASSIGNEE'),
			Role::SIGNER => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_TO_SIGNER'),
			Role::REVIEWER => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_TO_REVIEWER'),
			Role::EDITOR => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_TO_EDITOR'),
			default => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_TITLE'),
		};
	}

	private function getMessageChannelBlock(): Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		$value = match ($this->loadMessageData()?->getChannel()?->getType())
		{
			Timeline\SignDocument\Channel::TYPE_EMAIL => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_CHANNEL_EMAIL'),
			Timeline\SignDocument\Channel::TYPE_SMS => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_CHANNEL_SMS'),
			Timeline\SignDocument\Channel::TYPE_B24 => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_CHANNEL_B24'),
			default => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_CHANNEL_CHAT'),
		};

		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline()
			->setFixedWidth(false)
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_MESSAGE_SEND_CHANNEL_TITLE'))
			->setContentBlock(
				(new Layout\Body\ContentBlock\Text())
					->setValue($value)
			)
		;
	}

	private function getMessageDelivered(): ?string
	{
		if ($this->getMember()->role === Role::ASSIGNEE)
		{
			return Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERED_ASSIGNEE');
		}

		return Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERED');
	}

	private function isNeedChannel(): bool
	{
		return match ($this->model->getTypeCategoryId())
		{
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MESSAGE_SENT => true,
			default => false,
		};
	}

	private function isNeedProvider(): bool
	{
		return $this->getMember()?->role === Role::SIGNER;
	}

	private function getProviderNameByCode(string $providerCode): ?string
	{
		return match ($providerCode)
		{
			SignProviderCode::SES_RU->value => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_CHANNEL_SES'),
			SignProviderCode::SES_COM->value => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_CHANNEL_SES_COM'),
			SignProviderCode::SES_RU_EXPRESS->value => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_CHANNEL_SES_RU_EXPRESS'),
			SignProviderCode::GOS_KEY->value => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_CHANNEL_GOSKEY'),
			SignProviderCode::GOS_KEY_LITE->value => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_DOCUMENT_DELIVERY_CHANNEL_GOSKEY_LITE'),
			default => $providerCode,
		};
	}

	private function getReviewerBlockTitle(): ?string
	{
		return match ($this->model->getTypeCategoryId())
		{
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_REVIEWER => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_REVIEWER_DONE'),
			default => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_REVIEWER'),
		};
	}

	private function getEditorBlockTitle(): ?string
	{
		return match ($this->model->getTypeCategoryId())
		{
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_EDITOR => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EDITOR_DONE'),
			default => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EDITOR_MSG_1'),
		};
	}

	private function getResponsibleBlockTitle(): ?string
	{
		return match ($this->model->getTypeCategoryId())
		{
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_SIGNED_BY_RESPONSIBILITY_PERSON => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_RESPONSIBILITY_DONE'),
			default => Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_RESPONSIBILITY_MSG_1'),
		};
	}

	private function isNeedDocumentNameBlock(): bool
	{
		return match ($this->model->getTypeCategoryId())
		{
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_CREATED,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_DONE,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULLED,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULMENT_CANCELED => true,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_STOPPED => empty($this->loadDocumentData()->getInitiatorUserId()),
			default => false,
		};
	}

	private function isStoppedByInitiator(): bool
	{
		return in_array($this->model->getTypeCategoryId(), [
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_STOPPED,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_STOPPED_BY_ASSIGNEE,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_STOPPED_BY_REVIEWER,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_MEMBER_STOPPED_BY_EDITOR,
		], true);
	}

	private function getStopInitiatorBlock(): ?Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		$userId = $this->loadDocumentData()->getInitiatorUserId();
		if (!$userId)
		{
			return null;
		}

		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setFixedWidth(false)
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EMPLOYEE_STOPPED_INITIATOR_TITLE'))
			->setContentBlock($this->getUserNameLink($userId))
		;
	}

	private function isAnnulmentEvent(): bool
	{
		return in_array($this->model->getTypeCategoryId(), [
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULLED,
			Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULMENT_CANCELED,
		], true);
	}

	private function getAnnulmentInitiatorBlock(): ?Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		$userId = $this->loadDocumentData()->getInitiatorUserId();
		if (!$userId)
		{
			return null;
		}

		$title = $this->model->getTypeCategoryId() === Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULLED
			? Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_ANNULLED_INITIATOR_TITLE')
			: Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_ANNULMENT_CANCELED_INITIATOR_TITLE')
		;

		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setFixedWidth(false)
			->setTitle($title)
			->setContentBlock($this->getUserNameLink($userId))
		;
	}

	/**
	 * Employee (signer member) whose signing was annulled or restored.
	 *
	 * Only completed signers can be annulled, so the block is rendered for the
	 * signer role only. It is optional: document-level annulment events carry no
	 * member and the block is skipped, preserving the legacy layout.
	 */
	private function getAnnulledEmployeeBlock(): ?Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		$member = $this->getMember();
		if (!$member || $member->role !== Role::SIGNER)
		{
			return null;
		}

		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setFixedWidth(false)
			->setTitle(Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_EMPLOYEE'))
			->setContentBlock($this->getUserNameLink($member->entityId))
		;
	}

	/**
	 * Scale of a mass annulment: one entry covers the whole action, so the number
	 * of affected signings is what tells a partial annulment from a complete one.
	 *
	 * Rendered instead of the employee block, which a mass action has no single
	 * value for. A single record is skipped only on a member-scoped entry, where
	 * the employee block names it; a document-scoped entry keeps the number even
	 * then. Entries written before the counter existed carry none.
	 */
	private function getAnnulledMembersCountBlock(): ?Layout\Body\ContentBlock\ContentBlockWithTitle
	{
		$count = $this->loadDocumentData()->getAnnulledMembersCount();
		if ($count === null || $count < 1 || ($count === 1 && $this->getMember() !== null))
		{
			return null;
		}

		$title = $this->model->getTypeCategoryId() === Timeline\SignB2eDocument\Entry::TYPE_CATEGORY_ANNULLED
			? Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_ANNULLED_COUNT_TITLE')
			: Loc::getMessage('CRM_SERVICE_TIMELINE_LAYOUT_SIGNB2EDOCUMENT_ANNULMENT_CANCELED_COUNT_TITLE')
		;

		return (new Layout\Body\ContentBlock\ContentBlockWithTitle())
			->setInline(true)
			->setFixedWidth(false)
			->setTitle($title)
			->setContentBlock(
				(new Layout\Body\ContentBlock\Text())
					->setValue((string)$count)
			)
		;
	}

	private function getChannelIcon(): string
	{
		return match ($this->loadMessageData()?->getChannel()?->getType())
		{
			Timeline\SignDocument\Channel::TYPE_EMAIL => Icon::MAIL_OUTCOME,
			Timeline\SignDocument\Channel::TYPE_SMS => Icon::SMS,
			default => Icon::IM,
		};
	}

	private function getErrorContentBlock(): ?Layout\Body\ContentBlock
	{
		$messageData = $this->loadMessageData();
		if (!$messageData || empty($messageData->getError()?->getMessage()))
		{
			return null;
		}

		return (new Layout\Body\ContentBlock\Text())
			->setValue($messageData->getError()?->getMessage())
			->setIsMultiline()
		;
	}

}
