<?php

namespace Bitrix\Rest\V3\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\ToolDescriptionDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\Tool\ToolNameFormatter;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\AiAssistant\Definition\ToolSet\Contract\ToolProviderDefinitionInterface;
use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Exceptions\ObjectNotFoundException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\AiAssistant\Request\Dto\RequestSubjectDto;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\CacheManager;
use Bitrix\Rest\V3\Mcp\Access\DefaultMethodAccessChecker;
use Bitrix\Rest\V3\Mcp\Access\MethodAccessCheckerInterface;
use Bitrix\Rest\V3\Mcp\Description\ToolDescriptionBuilder;
use Bitrix\Rest\V3\Mcp\Execution\RestMethodInvoker;
use Bitrix\Rest\V3\Mcp\McpToolMetadata;
use Bitrix\Rest\V3\Mcp\Tool\RestMethodTool;
use Bitrix\Rest\V3\Realisation\Controller\Field;
use Bitrix\Rest\V3\Schema\MethodDescription;
use Bitrix\Rest\V3\Schema\SchemaManager;

abstract class RestToolSetProvider extends BaseToolSet implements ToolProviderDefinitionInterface
{
	private const TOOL_MAPS_CACHE_KEY = 'rest.v3.Mcp.toolMaps.cache.key.';
	private const CACHE_TOOL_DESCRIPTIONS = 'toolDescriptions';
	private const CACHE_METHOD_DESCRIPTIONS = 'methodDescriptions';
	private const CACHE_BUILT_AT = 'builtAt';
	private const CACHE_VERSION = 'version';
	private const TOOL_MAPS_CACHE_VERSION = 5;

	/** @var array<string, ToolDescriptionDto>|null */
	private ?array $toolDescriptionsMap = null;

	/** @var array<string, MethodDescription>|null */
	private ?array $methodDescriptionsByToolName = null;

	/**
	 * ToolManager instantiates a definition as new $definition->class() with no arguments, so constructor
	 * injection never reaches this class: dependencies stay optional and are resolved lazily by the getters below.
	 */
	public function __construct(
		private readonly ?SchemaManager $schemaManager = null,
		private readonly ?ToolDescriptionBuilder $toolDescriptionBuilder = null,
		private readonly ?RestMethodInvoker $restMethodInvoker = null,
		private readonly ?TracedLogger $tracedLogger = null,
		private readonly ?MethodAccessCheckerInterface $accessChecker = null,
	)
	{
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto();
	}

	public function getAdditionalRequiredModules(): array
	{
		return ['rest', $this->getOwnerModuleId()];
	}

	public function getProviderToolDefinitionsGenerator(
		int $userId,
		?RequestSubjectDto $subject = null,
	): \Generator
	{
		foreach ($this->getToolDescriptionsMap() as $toolName => $toolDescription)
		{
			$methodDescription = $this->getMethodDescriptionsByToolName()[$toolName] ?? null;
			if ($methodDescription === null)
			{
				continue;
			}

			if (!$this->getAccessChecker()->canAccess($methodDescription, $userId))
			{
				continue;
			}

			yield $toolDescription;
		}
	}

	public function getProviderToolDescription(
		string $toolName,
		int $userId,
		?RequestSubjectDto $subject = null,
	): ?ToolDescriptionDto
	{
		$methodDescription = $this->getMethodDescriptionsByToolName()[$toolName] ?? null;
		if ($methodDescription === null)
		{
			return null;
		}

		if (!$this->getAccessChecker()->canAccess($methodDescription, $userId))
		{
			return null;
		}

		return $this->getToolDescriptionsMap()[$toolName] ?? null;
	}

	public function getProviderToolExecutor(
		string $toolName,
		int $userId,
		?RequestSubjectDto $subject = null,
	): \Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract|\Bitrix\AiAssistant\Definition\Tool\Contract\SubjectAwareToolContract
	{
		$methodDescription = $this->getMethodDescriptionsByToolName()[$toolName] ?? null;
		if ($methodDescription === null)
		{
			throw new ObjectNotFoundException("Tool {$toolName} not found.");
		}

		$toolDescription = $this->getToolDescriptionsMap()[$toolName] ?? null;
		if ($toolDescription === null)
		{
			throw new ObjectNotFoundException("Tool {$toolName} description not found.");
		}

		return new RestMethodTool(
			$this->getTracedLogger(),
			$toolDescription,
			$methodDescription,
			$this->getRestMethodInvoker(),
			$this->getAccessChecker(),
		);
	}

	public function getRegistryToolNames(): array
	{
		return array_keys($this->getToolDescriptionsMap());
	}

	abstract protected function getOwnerModuleId(): string;

	/**
	 * @return array<string, ToolDescriptionDto>
	 */
	private function getToolDescriptionsMap(): array
	{
		$this->ensureToolMapsBuilt();

		return $this->toolDescriptionsMap ?? [];
	}

	/**
	 * @return array<string, MethodDescription>
	 */
	private function getMethodDescriptionsByToolName(): array
	{
		$this->ensureToolMapsBuilt();

		return $this->methodDescriptionsByToolName ?? [];
	}

	private function ensureToolMapsBuilt(): void
	{
		if ($this->toolDescriptionsMap !== null && $this->methodDescriptionsByToolName !== null)
		{
			return;
		}

		if ($this->fillToolMapsFromCache())
		{
			return;
		}

		$toolDescriptions = [];
		$methodDescriptionsByToolName = [];
		$exportedMethodDescriptions = $this->getExportedMethodDescriptions();
		$this->ensureGeneratedDtoClassesLoaded($exportedMethodDescriptions);
		foreach ($exportedMethodDescriptions as $methodDescription)
		{
			$toolDescription = $this->getToolDescriptionBuilder()->build($methodDescription);
			if ($toolDescription === null)
			{
				continue;
			}

			$normalizedToolName = ToolNameFormatter::format($toolDescription->name);
			if (array_key_exists($normalizedToolName, $toolDescriptions))
			{
				throw new McpException(
					sprintf('Duplicate provider tool name "%s" after normalization', $normalizedToolName),
				);
			}

			$toolDescriptions[$normalizedToolName] = $toolDescription;
			$methodDescriptionsByToolName[$normalizedToolName] = $methodDescription;
		}

		$this->toolDescriptionsMap = $toolDescriptions;
		$this->methodDescriptionsByToolName = $methodDescriptionsByToolName;

		CacheManager::set(
			$this->getToolMapsCacheKey(),
			[
				self::CACHE_TOOL_DESCRIPTIONS => $toolDescriptions,
				self::CACHE_METHOD_DESCRIPTIONS => $methodDescriptionsByToolName,
				self::CACHE_BUILT_AT => time(),
				self::CACHE_VERSION => self::TOOL_MAPS_CACHE_VERSION,
			],
			CacheManager::ONE_HOUR_TTL,
		);
	}

	/**
	 * Tool names, descriptions and JSON schemas do not depend on the user, and building them walks
	 * every method description with reflection over controllers and DTOs. Access is checked separately, per call.
	 */
	private function fillToolMapsFromCache(): bool
	{
		return $this->fillToolMapsFromCacheData(CacheManager::get($this->getToolMapsCacheKey()));
	}

	/**
	 * The maps carry the enabled state of the routes they were built from, so they must not outlive the schema:
	 * the build time is stored and checked here, because CacheManager reads every entry with its own long TTL.
	 */
	private function fillToolMapsFromCacheData(mixed $cached): bool
	{
		if (
			!is_array($cached)
			|| !is_array($cached[self::CACHE_TOOL_DESCRIPTIONS] ?? null)
			|| !is_array($cached[self::CACHE_METHOD_DESCRIPTIONS] ?? null)
			|| ($cached[self::CACHE_VERSION] ?? null) !== self::TOOL_MAPS_CACHE_VERSION
		)
		{
			return false;
		}

		$builtAt = $cached[self::CACHE_BUILT_AT] ?? null;
		if (!is_int($builtAt) || $builtAt < time() - CacheManager::ONE_HOUR_TTL)
		{
			return false;
		}

		$this->toolDescriptionsMap = $cached[self::CACHE_TOOL_DESCRIPTIONS];
		$this->methodDescriptionsByToolName = $cached[self::CACHE_METHOD_DESCRIPTIONS];

		return true;
	}

	private function getToolMapsCacheKey(): string
	{
		return self::TOOL_MAPS_CACHE_KEY . static::class;
	}

	/**
	 * @return array<string, MethodDescription>
	 */
	private function getExportedMethodDescriptions(): array
	{
		$methodDescriptions = $this->getAllMethodDescriptions();
		$this->getSchemaManager()->ensureGeneratedDtoClassesLoadedFromMethodDescriptions($methodDescriptions);
		$exportedMethods = [];
		$entityPrefixes = [];
		$entityMetadata = [];
		$entityQueryParams = [];
		foreach ($methodDescriptions as $actionUri => $methodDescription)
		{
			if ($methodDescription->module !== $this->getOwnerModuleId())
			{
				continue;
			}

			if (
				$methodDescription->mcpTool === null
				|| !$methodDescription->mcpTool->export
				|| $methodDescription->mcpTool->toolSetCode !== $this->getCode()
			)
			{
				continue;
			}

			$exportedMethods[$actionUri] = $methodDescription;
			$entityPrefix = $this->getEntityPrefix($actionUri);
			if ($entityPrefix !== null && !$this->isSystemFieldController($methodDescription->controllerFqcn))
			{
				$entityPrefixes[$entityPrefix] = $entityPrefix;
				$entityMetadata[$entityPrefix] = $methodDescription->mcpTool;
				$entityQueryParams[$entityPrefix] = $methodDescription->queryParams;
			}
		}

		foreach ($entityPrefixes as $entityPrefix)
		{
			foreach (['field.list', 'field.get'] as $fieldAction)
			{
				$actionUri = $entityPrefix . '.' . $fieldAction;
				if (isset($exportedMethods[$actionUri]))
				{
					continue;
				}

				$fieldMethod = $methodDescriptions[$actionUri] ?? null;
				if ($fieldMethod === null || $this->belongsToAnotherToolSet($fieldMethod))
				{
					continue;
				}

				// a route dropped from the export must not get the metadata back from its entity
				$inheritedMetadata = $fieldMethod->isMcpExportSuppressed()
					? null
					: $this->inheritEntityToolMetadata($entityMetadata[$entityPrefix] ?? null);

				$exportedMethods[$actionUri] = new MethodDescription(
					module: $fieldMethod->module,
					controllerFqcn: $fieldMethod->controllerFqcn,
					method: $fieldMethod->method,
					dtoFqcn: $fieldMethod->dtoFqcn,
					scopes: $fieldMethod->scopes,
					actionUri: $fieldMethod->actionUri,
					title: $fieldMethod->title,
					description: $fieldMethod->description,
					isEnabled: $fieldMethod->isEnabled,
					queryParams: $this->inheritEntityQueryParams(
						$fieldMethod->queryParams,
						$entityQueryParams[$entityPrefix] ?? null,
					),
					isHidden: $fieldMethod->isHidden,
					mcpTool: $fieldMethod->mcpTool ?? $inheritedMetadata,
					isDeprecated: $fieldMethod->isDeprecated,
					// the same description is executed, so the access provider of the route and the
					// resource it decides about must survive the rebuild
					accessProvider: $fieldMethod->accessProvider,
					resourceDtoFqcn: $fieldMethod->resourceDtoFqcn,
				);
			}
		}

		return $exportedMethods;
	}

	/**
	 * A route that declares a tool set of its own is completed by that tool set only: the first pass drops it here,
	 * and the entity prefix must not bring it back, publishing the same field dictionary in two sets.
	 */
	private function belongsToAnotherToolSet(MethodDescription $methodDescription): bool
	{
		return $methodDescription->mcpTool !== null
			&& $methodDescription->mcpTool->toolSetCode !== $this->getCode();
	}

	/**
	 * A field dictionary speaks the vocabulary of DtoFieldDto (name, type, title) and not the fields of the entity,
	 * so hiddenFields and fieldWhitelist stay with the entity: applied here they would match a foreign vocabulary.
	 * The tool name belongs to a single route and is not inherited either.
	 */
	private function inheritEntityToolMetadata(?McpToolMetadata $entityMetadata): ?McpToolMetadata
	{
		if ($entityMetadata === null)
		{
			return null;
		}

		return new McpToolMetadata(
			export: $entityMetadata->export,
			toolSetCode: $entityMetadata->toolSetCode,
			descriptionAdditions: $entityMetadata->descriptionAdditions,
		);
	}

	/**
	 * Fixed route params that identify the entity a field dictionary belongs to. A generated dictionary
	 * carries no such param of its own, while the access check of the owner module may depend on it.
	 *
	 * @return string[]
	 */
	protected function getInheritedEntityQueryParams(): array
	{
		return [];
	}

	private function inheritEntityQueryParams(?array $fieldQueryParams, ?array $entityQueryParams): ?array
	{
		if ($entityQueryParams === null)
		{
			return $fieldQueryParams;
		}

		foreach ($this->getInheritedEntityQueryParams() as $name)
		{
			if (!isset($entityQueryParams[$name]) || isset($fieldQueryParams[$name]))
			{
				continue;
			}

			$fieldQueryParams ??= [];
			$fieldQueryParams[$name] = $entityQueryParams[$name];
		}

		return $fieldQueryParams;
	}

	private function getEntityPrefix(string $actionUri): ?string
	{
		$parts = explode('.', $actionUri);
		if (count($parts) < 2)
		{
			return null;
		}

		array_pop($parts);

		return implode('.', $parts);
	}

	private function isSystemFieldController(?string $controllerFqcn): bool
	{
		return in_array(
			$controllerFqcn,
			[
				Field::class,
				Field\Custom::class,
				Field\Custom\Enum::class,
			],
			true,
		);
	}

	/**
	 * A separate method so that a test can drive the export without the portal schema.
	 *
	 * @return array<string, MethodDescription>
	 */
	protected function getAllMethodDescriptions(): array
	{
		return $this->getSchemaManager()->getMethodDescriptions();
	}

	/**
	 * @param MethodDescription[] $methodDescriptions
	 */
	protected function ensureGeneratedDtoClassesLoaded(array $methodDescriptions): void
	{
		$this->getSchemaManager()->ensureGeneratedDtoClassesLoadedFromMethodDescriptions($methodDescriptions);
	}

	private function getSchemaManager(): SchemaManager
	{
		return $this->schemaManager ?? ServiceLocator::getInstance()->get(SchemaManager::class);
	}

	private function getToolDescriptionBuilder(): ToolDescriptionBuilder
	{
		return $this->toolDescriptionBuilder ?? new ToolDescriptionBuilder(
			new \Bitrix\Rest\V3\Mcp\Verb\VerbRegistry(),
			new \Bitrix\Rest\V3\Mcp\Schema\ToolSchemaBuilder(),
			$this->getTracedLogger(),
		);
	}

	private function getRestMethodInvoker(): RestMethodInvoker
	{
		return $this->restMethodInvoker ?? new RestMethodInvoker();
	}

	private function getTracedLogger(): TracedLogger
	{
		return $this->tracedLogger ?? ServiceLocator::getInstance()->get(TracedLogger::class);
	}

	/**
	 * A module whose methods are guarded by entity-level rights returns its own checker here.
	 */
	protected function getAccessChecker(): MethodAccessCheckerInterface
	{
		return $this->accessChecker ?? new DefaultMethodAccessChecker();
	}
}
