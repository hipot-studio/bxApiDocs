<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Mail;

use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Integration\AI\Settings;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Sender;

class MailTemplateEditorSettings
{
	public function sanitizeHtml(string $bodyHtml): string
	{
		$bodyHtml = preg_replace('/<!--.*?-->/is', '', $bodyHtml);
		$bodyHtml = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $bodyHtml);
		$bodyHtml = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $bodyHtml);
		$sanitizer = new \CBXSanitizer();
		$sanitizer->setLevel(\CBXSanitizer::SECURE_LEVEL_LOW);
		$sanitizer->applyDoubleEncode(false);
		$sanitizer->addTags($this->getAllowedHtmlTags());
		$bodyHtml = $sanitizer->sanitizeHtml($bodyHtml);

		return preg_replace('/https?:\/\/bxacid:(n?\d+)/i', 'bxacid:\1', $bodyHtml);
	}

	/** @return list<array{value: string, label: string}> */
	public function getSenders(int $userId, string $savedSender): array
	{
		$senders = [];
		$values = ['' => true];
		foreach (Sender::prepareUserMailboxes($userId) as $mailbox)
		{
			$value = (string)$mailbox['formated'];
			if (!isset($values[$value]))
			{
				$senders[] = ['value' => $value, 'label' => $value];
				$values[$value] = true;
			}
		}

		if (!isset($values[$savedSender]))
		{
			$senders[] = ['value' => $savedSender, 'label' => $savedSender];
		}

		return $senders;
	}

	public function canAddSender(): bool
	{
		return Loader::includeModule('bitrix24') || (bool)Configuration::getValue('smtp');
	}

	public function getCopilotParams(): array
	{
		if (
			!Loader::includeModule('mail')
			|| !class_exists(Settings::class)
			|| !method_exists(Settings::class, 'instance')
			|| !method_exists(Settings::class, 'getMailCrmCopilotParams')
			|| !defined(Settings::class . '::MAIL_CRM_NEW_MESSAGE_CONTEXT_ID')
		)
		{
			return ['isCopilotEnabled' => false, 'isCopilotImageEnabled' => false];
		}

		$params = Settings::instance()->getMailCrmCopilotParams(Settings::MAIL_CRM_NEW_MESSAGE_CONTEXT_ID);
		$params['isCopilotImageEnabled'] = false;

		return $params;
	}

	public function getAllowedHtmlTags(): array
	{
		if (
			Loader::includeModule('mail')
			&& class_exists(Message::class)
			&& method_exists(Message::class, 'getWhitelistTagAttributes')
		)
		{
			return Message::getWhitelistTagAttributes();
		}

		return ['style' => []];
	}
}
