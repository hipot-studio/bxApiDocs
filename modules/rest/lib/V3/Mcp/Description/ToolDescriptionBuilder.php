<?php

namespace Bitrix\Rest\V3\Mcp\Description;

use Bitrix\AiAssistant\Definition\Dto\ToolDescriptionDto;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\TypeHelper;
use Bitrix\Rest\V3\Mcp\Schema\ToolInputSchema;
use Bitrix\Rest\V3\Mcp\Schema\ToolSchemaBuilder;
use Bitrix\Rest\V3\Mcp\Verb\VerbRegistry;
use Bitrix\Rest\V3\Schema\MethodDescription;

final class ToolDescriptionBuilder
{
	public function __construct(
		private readonly VerbRegistry $verbRegistry,
		private readonly ToolSchemaBuilder $schemaBuilder,
		private readonly ?TracedLogger $tracedLogger = null,
	)
	{
	}

	public function build(MethodDescription $methodDescription): ?ToolDescriptionDto
	{
		$mcpTool = $methodDescription->mcpTool;
		if ($mcpTool === null || !$mcpTool->export || !$methodDescription->isEnabled)
		{
			return null;
		}

		$verb = $this->verbRegistry->resolve($methodDescription);
		if ($verb === null && $mcpTool->descriptionOverride === null)
		{
			$this->tracedLogger?->warning(sprintf(
				'REST MCP: route "%s" declares McpTool but is not exported, VerbRegistry knows no verb "%s"'
				. ' and the attribute sets no descriptionOverride.',
				$methodDescription->actionUri,
				$methodDescription->method,
			));

			return null;
		}

		$entityTitle = $this->resolveEntityTitle($methodDescription);
		$baseDescription = $verb?->getDescription($entityTitle);
		$description = $mcpTool->descriptionOverride ?? $this->appendDescriptionAdditions(
			$baseDescription ?? '',
			$mcpTool->descriptionAdditions,
		);

		if ($description === '')
		{
			return null;
		}

		return new ToolDescriptionDto(
			name: $mcpTool->nameOverride ?? str_replace('.', '_', $methodDescription->actionUri),
			description: $description,
			inputSchema: new ToolInputSchema($this->schemaBuilder->build($methodDescription)),
			annotations: $verb?->getAnnotations(),
		);
	}

	/**
	 * The entity the route is about, which for a field dictionary is the described entity and not its own
	 * DtoFieldDto: the description reads "List available fields for deal", not for a field.
	 */
	private function resolveEntityTitle(MethodDescription $methodDescription): string
	{
		$fieldResourceUri = $this->resolveFieldResourceUri($methodDescription->actionUri);
		$dtoClass = $fieldResourceUri === null ? $methodDescription->dtoFqcn : null;
		if (
			isset($methodDescription->queryParams['dtoClass'])
			&& is_string($methodDescription->queryParams['dtoClass'])
		)
		{
			$dtoClass = $methodDescription->queryParams['dtoClass'];
		}

		if (TypeHelper::isDto($dtoClass))
		{
			$reflection = new \ReflectionClass($dtoClass);
			$titleAttributes = $reflection->getAttributes(Title::class);
			if ($titleAttributes !== [])
			{
				/** @var Title $title */
				$title = $titleAttributes[0]->newInstance();
				$value = $title->value;
				$resolvedTitle = $value instanceof LocalizableMessage
					? trim((string)$value->localize('en'))
					: trim((string)$value);

				if ($fieldResourceUri === null || $resolvedTitle !== '')
				{
					return $resolvedTitle;
				}
			}

			if ($fieldResourceUri !== null)
			{
				return $fieldResourceUri;
			}

			return strtolower($reflection->getShortName());
		}

		if ($fieldResourceUri !== null)
		{
			return $fieldResourceUri;
		}

		$segments = explode('.', $methodDescription->actionUri);

		return (string)($segments[count($segments) - 2] ?? $methodDescription->method);
	}

	private function resolveFieldResourceUri(string $actionUri): ?string
	{
		$normalizedActionUri = strtolower($actionUri);
		foreach (['.field.list', '.field.get'] as $suffix)
		{
			if (str_ends_with($normalizedActionUri, $suffix))
			{
				return substr($actionUri, 0, -strlen($suffix));
			}
		}

		return null;
	}

	private function appendDescriptionAdditions(string $description, array $descriptionAdditions): string
	{
		$description = trim($description);
		$descriptionAdditions = array_values(array_filter(array_map('trim', $descriptionAdditions)));

		if ($descriptionAdditions === [])
		{
			return $description;
		}

		$extraDescription = implode(' ', $descriptionAdditions);

		return $description === ''
			? $extraDescription
			: $description . ' ' . $extraDescription;
	}
}
