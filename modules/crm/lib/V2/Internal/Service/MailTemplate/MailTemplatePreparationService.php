<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\MailTemplate;

use Bitrix\Crm\V2\Internal\Integration\Disk\MailTemplateAttachmentProvider;
use Bitrix\Crm\V2\Internal\Repository\MailTemplate\LegacyMailTemplateRepository;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class MailTemplatePreparationService
{
	public const ERROR_NOT_AVAILABLE = 'CRM_MAIL_TEMPLATE_NOT_AVAILABLE';
	public const ERROR_PREPARATION_FAILED = 'CRM_MAIL_TEMPLATE_PREPARATION_FAILED';

	public function __construct(
		private readonly LegacyMailTemplateRepository $repository = new LegacyMailTemplateRepository(),
		private readonly MailTemplateAttachmentProvider $attachmentProvider = new MailTemplateAttachmentProvider(),
	)
	{
	}

	/**
	 * @return Result Data shape on success: array{
	 *     id: int,
	 *     subject: string,
	 *     bodyHtml: string,
	 *     attachments: list<array{id: int, name: string, size: int, contentType: string|null}>
	 * }
	 */
	public function prepareUniversal(int $templateId, int $userId): Result
	{
		$result = new Result();
		$template = $this->repository->getUniversalForPreparation($templateId, $userId);
		if ($template === null)
		{
			return $result->addError(new Error(
				'Mail template is not available.',
				self::ERROR_NOT_AVAILABLE,
			));
		}

		try
		{
			$bodyHtml = $this->convertBodyToHtml($template['body'], $template['bodyType']);
			$bodyHtml = \CCrmTemplateManager::PrepareTemplate(
				$bodyHtml,
				0,
				0,
				\CCrmContentType::Html,
				$userId,
			);
			[$bodyHtml, $attachments] = $this->attachmentProvider->prepare(
				$bodyHtml,
				$template['attachmentIds'] ?? [],
				$userId,
				$template['id'],
			);
			$bodyHtml = $this->sanitizeBodyHtml($bodyHtml);
			$subject = \CCrmTemplateManager::PrepareTemplate(
				$template['subject'],
				0,
				0,
				\CCrmContentType::PlainText,
				$userId,
			);
		}
		catch (\Throwable)
		{
			return $result->addError(new Error(
				'Mail template preparation failed.',
				self::ERROR_PREPARATION_FAILED,
			));
		}

		return $result->setData([
			'id' => $template['id'],
			'subject' => $subject,
			'bodyHtml' => $bodyHtml,
			'attachments' => $attachments,
		]);
	}

	/**
	 * Only BBCode is parsed. Plain text carries no markup, so it is escaped, and every line break of the
	 * template becomes a break of the letter: a blank line stays a blank line, and a CRLF counts once.
	 */
	private function convertBodyToHtml(string $body, int $bodyType): string
	{
		if ($body === '' || $bodyType === \CCrmContentType::Html)
		{
			return $body;
		}

		if ($bodyType === \CCrmContentType::BBCode)
		{
			return (new \CTextParser())->convertText($body);
		}

		$escaped = htmlspecialcharsbx($body);

		return preg_replace('/\r\n|\r|\n/u', '<br>', $escaped) ?? $escaped;
	}

	private function sanitizeBodyHtml(string $bodyHtml): string
	{
		if ($bodyHtml === '')
		{
			return '';
		}

		foreach (['/<!--.*?-->/is', '/<script[^>]*>.*?<\/script>/is', '/<title[^>]*>.*?<\/title>/is'] as $pattern)
		{
			$bodyHtml = preg_replace($pattern, '', $bodyHtml) ?? $bodyHtml;
		}

		$sanitizer = new \CBXSanitizer();
		$sanitizer->setLevel(\CBXSanitizer::SECURE_LEVEL_LOW);
		$sanitizer->applyDoubleEncode(false);
		$sanitizer->addTags(['style' => []]);

		return $sanitizer->sanitizeHtml($bodyHtml);
	}
}
