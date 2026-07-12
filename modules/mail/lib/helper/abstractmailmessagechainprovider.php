<?php

namespace Bitrix\Mail\Helper;

use Bitrix\Main\Web\Uri;
use Bitrix\Mail\Helper\Message\Parsers;

abstract class AbstractMailMessageChainProvider implements MailMessageChainProviderInterface
{
	public const KEY_ID_IN_MESSAGE_BODY = 'id_in_message_body';

	public function replaceAttachmentPlaceholders(?string $body, array $attachments): string
	{
		if (is_null($body))
		{
			return '';
		}

		//Class is used not only in the 'Mail' module
		if (!\Bitrix\Main\Loader::includeModule('mail'))
		{
			return $body;
		}

		$newBody = $body;

		foreach ($attachments as $attachment)
		{
			if (
				isset($attachment[self::KEY_ID_IN_MESSAGE_BODY])
				&& (int)$attachment[self::KEY_ID_IN_MESSAGE_BODY] > 0
				&& isset($attachment['url'])
				&& $attachment['url'] !== ''
			)
			{
				$url = (new Uri($attachment['url']))->toAbsolute()->getLocator();
				$newBody = $this->replaceAttachmentPlaceholderWithUrl($newBody, (int)$attachment[self::KEY_ID_IN_MESSAGE_BODY], $url);
			}
		}

		return $newBody;
	}

	protected function replaceAttachmentPlaceholderWithUrl(string $body, int $imageId, string $url): string
	{
		return Parsers\ReplacingImagesLinks::parse($body, $imageId, $url);
	}

	public function cleanCharset(?string $body): string
	{
		if (is_null($body))
		{
			return '';
		}

		//Class is used not only in the 'Mail' module
		if (!\Bitrix\Main\Loader::includeModule('mail'))
		{
			return $body;
		}

		return Parsers\CharsetCleaner::parse($body);
	}

	/*
		Gmail and some other label-based providers (labels act as folders, one message
		can have many) return the same message once per label — keep the first row only.
	*/
	protected static function deduplicateRowsByMessageId(array $rows): array
	{
		$seen = [];
		$unique = [];

		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			if (isset($seen[$id]))
			{
				continue;
			}
			$seen[$id] = true;
			$unique[] = $row;
		}

		return $unique;
	}
}