<?php

namespace Bitrix\Crm\Service\Timeline\Item\Activity\Sms;

use Bitrix\Crm\Activity\Provider\Sms\PlaceholderContext;
use Bitrix\Crm\Activity\Provider\Sms\PlaceholderManager;
use Bitrix\Crm\Component\EntityDetails\TimelineMenuBar;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Layout\Body\Logo;
use Bitrix\Crm\Service\Timeline\Layout\Common;
use Bitrix\Crm\Service\Timeline\Layout\Common\Icon;
use Bitrix\Main\Localization\Loc;

final class Whatsapp extends Sms
{
	protected function getActivityTypeId(): string
	{
		return 'Whatsapp';
	}

	public function getTitle(): ?string
	{
		return Loc::getMessage('CRM_TIMELINE_TITLE_ACTIVITY_WHATSAPP_TITLE_MSGVER_1');
	}

	public function getIconCode(): ?string
	{
		return Icon::WHATSAPP;
	}

	public function getLogo(): ?Logo
	{
		return Common\Logo::getInstance(Common\Logo::CHANNEL_WHATSAPP)->createLogo();
	}

	protected function isResendingAvailable(): bool
	{
		if (Feature::enabled(Feature\MessageSenderEditor::class))
		{
			return (new TimelineMenuBar\Item\Message($this->getMenuBarContext()))->isAvailable();
		}

		return (new TimelineMenuBar\Item\WhatsApp($this->getMenuBarContext()))->isAvailable();
	}

	protected function getResendData(): array
	{
		$data = parent::getResendData();
		unset($data['text']);

		$smsInfo = $this->getAssociatedEntityModel()?->get('SMS_INFO');

		return [
			...$data,
			'template' => $this->getUsedTemplate($smsInfo['senderId'], (int)($smsInfo['templateId'])),
		];
	}

	private function getUsedTemplate(string $senderId, int $templateId): array
	{
		if ($templateId <= 0)
		{
			return [];
		}

		$sender = SmsManager::getSenderById($senderId);
		if (!$sender?->canUse())
		{
			return [];
		}

		$categoryId = $this->getContext()->getEntityCategoryId()
			?? Container::getInstance()
				->getFactory($this->getContext()->getEntityTypeId())
				?->getItemCategoryId($this->getContext()->getEntityId())
		;

		$list = $sender?->getTemplatesList([
			'module' => 'crm',
			'entityTypeId' => $this->getContext()->getEntityTypeId(),
			'entityId' => $this->getContext()->getEntityId(),
			'entityCategoryId' => $categoryId,
		]);
		if (empty($list))
		{
			return [];
		}

		$template = array_values(
			array_filter(
				$list,
				static fn(array $item) => $item['ORIGINAL_ID'] === $templateId
			),
		)[0] ?? [];
		if (empty($template))
		{
			return [];
		}

		$filledPlaceholders = (new PlaceholderManager())->getPlaceholders(
			[$template['ORIGINAL_ID']],
			PlaceholderContext::createInstance(
				$this->getContext()->getEntityTypeId(),
				$categoryId
			)
		);

		foreach ($filledPlaceholders as $filledPlaceholder)
		{
			if ($template['ORIGINAL_ID'] !== (int)$filledPlaceholder['TEMPLATE_ID'])
			{
				continue;
			}

			if (!isset($template['FILLED_PLACEHOLDERS']))
			{
				$template['FILLED_PLACEHOLDERS'] = [];
			}

			$template['FILLED_PLACEHOLDERS'][] = $filledPlaceholder;
		}

		return $template;
	}
}
