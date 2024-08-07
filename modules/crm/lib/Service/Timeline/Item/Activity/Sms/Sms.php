<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity\Sms;

use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Action\JsEvent;
use Bitrix\Crm\Service\Timeline\Layout\Action\Redirect;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ContentBlockFactory;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\LineOfTextBlocks;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Text;
use Bitrix\Crm\Service\Timeline\Layout\Body\Logo;
use Bitrix\Crm\Service\Timeline\Layout\Common;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Main\Localization\Loc;

class Sms extends Base
{
	protected function getActivityTypeId(): string
	{
		return 'Sms';
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_TITLE_ACTIVITY_SMS_TITLE');
	}

	public function getIconCode(): ?string
	{
		return Icon::SMS;
	}

	public function getLogo(): ?Logo
	{
		return Common\Logo::getInstance(Common\Logo::SMS)->createLogo();
	}

	protected function getMessageText(): ?string
	{
		return $this->getAssociatedEntityModel()?->get('DESCRIPTION_RAW');
	}

	protected function getMessageSentViaContentBlock(): ?ContentBlock
	{
		$sentByRobot = $this->isSentByRobot();

		$smsInfo = $this->getAssociatedEntityModel()?->get('SMS_INFO');
		$smsInfo = $smsInfo ?? [];

		$senderId = $smsInfo['senderId'] ?? '';
		$senderName = $smsInfo['senderShortName'] ?? '';
		$fromName = $smsInfo['fromName'] ?? '';

		if ($senderId === 'rest' && $fromName)
		{
			$senderName = $fromName;
		}

		$message = Loc::getMessage(
			$sentByRobot
				? 'CRM_TIMELINE_TITLE_ACTIVITY_NOTIFICATION_SENT_BY_ROBOT_VIA_SERVICE'
				: 'CRM_TIMELINE_TITLE_ACTIVITY_NOTIFICATION_SENT_VIA_SERVICE',
			[
				'#SERVICE_NAME#' => $senderName,
			]
		);

		if ($senderId !== 'rest' && $fromName)
		{
			$message = Loc::getMessage($sentByRobot
					? 'CRM_TIMELINE_TITLE_ACTIVITY_NOTIFICATION_SENT_BY_ROBOT_VIA_SERVICE_FULL'
					: 'CRM_TIMELINE_TITLE_ACTIVITY_NOTIFICATION_SENT_VIA_SERVICE_FULL',
				[
					'#SERVICE_NAME#' => $senderName,
					'#PHONE_NUMBER#' => $fromName,
				]
			);
		}

		return (new Text())->setValue($message)->setColor(Text::COLOR_BASE_60);
	}

	protected function buildUserContentBlock(): ?ContentBlock
	{
		$providerParams = $this->getAssociatedEntityModel()?->get('PROVIDER_PARAMS') ?? [];
		$recipientUserId = (int)($providerParams['recipient_user_id'] ?? 0);
		if (!$recipientUserId)
		{
			return null;
		}
		$communication = $this->getAssociatedEntityModel()?->get('COMMUNICATION') ?? [];
		$phone = $communication['FORMATTED_VALUE'] ?? null;
		if (!$phone)
		{
			return null;
		}
		$userInfo = Container::getInstance()->getUserBroker()->getById($recipientUserId);
		$userName = $userInfo['FORMATTED_NAME'] ?? null;
		$userDetailsUrl = $userInfo['SHOW_URL'] ?? null;
		if (!$userName)
		{
			return null;
		}
		$textOrLink = ContentBlockFactory::createTextOrLink($userName . ' ' . $phone, $userDetailsUrl ? new Redirect($userDetailsUrl) : null);

		return (new LineOfTextBlocks())
			->addContentBlock(
				'title',
				ContentBlockFactory::createTitle(Loc::getMessage('CRM_TIMELINE_ACTIVITY_SMS_RECIPIENT'))
			)
			->addContentBlock('data', $textOrLink->setIsBold(isset($userDetailsUrl))->setColor(Text::COLOR_BASE_90))
		;
	}

	protected function getResendingAction(): ?Action
	{
		$menuBarItem = new TimelineMenuBar\Item\Sms($this->getMenuBarContext());
		if (
			!$menuBarItem->isAvailable()
			|| $this->isSentByRobot()
		)
		{
			return null;
		}

		return (new JsEvent('Activity:Sms:Resend'))
			->addActionParamArray('params', $this->getResendData())
		;
	}

	protected function isSentByRobot(): bool
	{
		$providerParams = $this->getAssociatedEntityModel()?->get('PROVIDER_PARAMS') ?? [];

		return ($providerParams['sender'] ?? '')  === 'robot';
	}

	final protected function getClient(): array
	{
		$communication = $this->getAssociatedEntityModel()?->get('COMMUNICATION') ?? [];

		return [
			'entityTypeId' => $communication['ENTITY_TYPE_ID'] ?? null,
			'entityId' => $communication['ENTITY_ID'] ?? null,
			'value' => $communication['VALUE'] ?? null,
		];
	}

	private function getResendData(): array
	{
		$smsInfo = $this->getAssociatedEntityModel()?->get('SMS_INFO');

		return [
			'text' => $this->getMessageText(),
			'senderId' => $smsInfo['senderId'] ?? '',
			'from' => $smsInfo['from'] ?? '',
			'client' => $this->getClient(),
		];
	}
}
