<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeAuthorType;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeRequestSource;

final class TemplateChangeAttribution
{
	private function __construct(
		public readonly TemplateChangeAuthorType $authorType,
		public readonly ?int $userId,
		public readonly ?string $aiTool,
		public readonly ?int $initiatorUserId,
		public readonly TemplateChangeRequestSource $requestSource,
	)
	{}

	public static function forUser(int $userId, TemplateChangeRequestSource $requestSource): self
	{
		if ($userId <= 0)
		{
			return self::forSystem($requestSource);
		}

		return new self(TemplateChangeAuthorType::Human, $userId, null, null, $requestSource);
	}

	public static function forAi(
		?string $aiTool,
		?int $initiatorUserId,
		TemplateChangeRequestSource $requestSource,
	): self
	{
		return new self(
			TemplateChangeAuthorType::Ai,
			null,
			\CBPHelper::isEmptyValue($aiTool) ? null : $aiTool,
			$initiatorUserId > 0 ? $initiatorUserId : null,
			$requestSource,
		);
	}

	public static function forSystem(
		TemplateChangeRequestSource $requestSource = TemplateChangeRequestSource::System,
	): self
	{
		return new self(TemplateChangeAuthorType::System, null, null, null, $requestSource);
	}
}
