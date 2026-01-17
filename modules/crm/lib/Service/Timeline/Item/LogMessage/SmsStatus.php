<?php

namespace Bitrix\Crm\Service\Timeline\Item\LogMessage;

use Bitrix\Crm\Activity\Provider\BaseMessage;
use Bitrix\Crm\Activity\Provider\Notification;
use Bitrix\Crm\Activity\Provider\Telegram;
use Bitrix\Crm\Activity\Provider\WhatsApp;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\LogMessage;
use Bitrix\Crm\Service\Timeline\Item\Model;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Client;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\LineOfTextBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Text;
use Bitrix\Crm\Service\Timeline\Layout\Header\Tag;
use Bitrix\Main\Localization\Loc;

class SmsStatus extends LogMessage
{
	private array $messageInfo;

	public function __construct(Context $context, Model $model)
	{
		if (!empty($model->getAssociatedEntityModel()?->get('SMS_INFO')))
		{
			$this->messageInfo = $model->getAssociatedEntityModel()?->get('SMS_INFO') ?? [];
		}
		else
		{
			$messageInfo = $model->getAssociatedEntityModel()?->get('MESSAGE_INFO') ?? [];
			$this->messageInfo = $messageInfo['HISTORY_ITEMS'][0] ?? [];
		}

		parent::__construct($context, $model);
	}

	public function getType(): string
	{
		return 'SmsStatus';
	}

	public function getIconCode(): ?string
	{
		return match ($this->getStatus())
		{
			BaseMessage::MESSAGE_FAILURE => 'info',
			BaseMessage::MESSAGE_SUCCESS => 'comment',
			BaseMessage::MESSAGE_READ => 'view',
			default => parent::getIconCode(),
		};

	}

	public function getTitle(): ?string
	{
		$messenger = $this->messageInfo['PROVIDER_DATA']['DESCRIPTION'] ?? '';
		$infix = $this->getStatusMessageInfix();

		return match ($this->getStatus())
		{
			BaseMessage::MESSAGE_FAILURE => Loc::getMessage(
				"CRM_TIMELINE_LOG_${infix}_STATUS_TITLE_FAILURE",
				['#MESSENGER#' => $messenger],
			),
			BaseMessage::MESSAGE_SUCCESS => Loc::getMessage(
				"CRM_TIMELINE_LOG_${infix}_STATUS_TITLE_SUCCESS",
				['#MESSENGER#' => $messenger],
			),
			BaseMessage::MESSAGE_READ => Loc::getMessage(
				"CRM_TIMELINE_LOG_${infix}_STATUS_TITLE_READ",
				['#MESSENGER#' => $messenger],
			),
			default => Loc::getMessage('CRM_TIMELINE_LOG_SMS_STATUS_TITLE_UNKNOWN'),
		};
	}

	private function getStatusMessageInfix(): string
	{
		if ($this->isNotification())
		{
			return 'MSG';
		}

		$activityProviderId = $this->getAssociatedEntityModel()?->get('PROVIDER_ID');

		return match ($activityProviderId)
		{
			WhatsApp::getId() => 'WHATSAPP',
			Telegram::getId() => 'TELEGRAM',
			default => 'SMS',
		};
	}

	public function getContentBlocks(): ?array
	{
		$result = [];

		$client = $this->buildClientBlock(Client::BLOCK_WITH_FORMATTED_VALUE, Loc::getMessage('CRM_TIMELINE_LOG_SMS_STATUS_RECIPIENT'));
		if ($client)
		{
			if ($client instanceof LineOfTextBlocks && !$this->isNotification())
			{
				$client->addContentBlock(
					'provider',
					(new Text())
						->setValue($this->messageInfo['senderShortName'] ?? '')
						->setColor(Text::COLOR_BASE_70)
				);
			}

			$result['recipient'] = $client;
		}

		return $result;
	}

	public function getTags(): ?array
	{
		$status = $this->getStatus();
		// render tags to FAILURE codes only
		if ($status !== BaseMessage::MESSAGE_FAILURE)
		{
			return null;
		}

		$statusTag = new Tag(Loc::getMessage('CRM_TIMELINE_LOG_TAG_SENDING_ERROR'), Tag::TYPE_FAILURE);

		$errorText = $this->isNotification()
			? ($this->messageInfo['ERROR_MESSAGE'] ?? '')
			: ($this->messageInfo['errorText'] ?? '');

		if (!empty($errorText))
		{
			$statusTag->setHint($errorText);
		}

		return [
			'status' => $statusTag,
		];
	}

	private function getStatus(): ?int
	{
		$activityData = $this->getModel()->getSettings()['ACTIVITY_DATA'];
		if (isset($activityData))
		{
			return $activityData['STATUS'] ?? null;
		}

		return null;
	}

	private function isNotification(): bool
	{
		return $this->getAssociatedEntityModel()?->get('PROVIDER_ID') === Notification::getId();
	}
}
