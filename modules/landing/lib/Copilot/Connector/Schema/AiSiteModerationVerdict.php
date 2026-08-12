<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

use Bitrix\Landing\AI\SiteBuilder\Dto\ModerationVerdictDto;
use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;

/**
 * Response schema of the AI-site topic moderation step.
 *
 * decision / category are enums bound to the ModerationVerdictDto machine contract.
 * JsonShape adds additionalProperties=false and makes all fields required.
 */
class AiSiteModerationVerdict extends Schema
{
	protected function getName(): string
	{
		return 'aiSiteModerationVerdictSchema';
	}

	protected function getStructure(): SchemaNodeDto
	{
		$properties = [
			new SchemaNodeDto('string', 'decision', ['enum' => ModerationVerdictDto::DECISIONS]),
			new SchemaNodeDto('string', 'category', ['enum' => ModerationVerdictDto::CATEGORY_CODES]),
			new SchemaNodeDto('string', 'hint'),
		];

		return new SchemaNodeDto('object', null, [
			'properties' => $properties,
		]);
	}
}
