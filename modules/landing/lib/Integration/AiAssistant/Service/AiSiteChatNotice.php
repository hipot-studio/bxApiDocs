<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

/**
 * A server message of landing for the chat of a site: its type, the site it names and the link of its button.
 */
final class AiSiteChatNotice
{
	public const TYPE_CREATED = 'created';
	public const TYPE_FAILED = 'failed';
	public const TYPE_DELETED = 'deleted';

	public function __construct(
		public readonly string $type,
		public readonly string $siteTitle = '',
		public readonly ?string $buttonUrl = null,
	)
	{
	}
}
