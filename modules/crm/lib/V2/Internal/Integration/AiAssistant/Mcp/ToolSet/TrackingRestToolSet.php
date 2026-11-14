<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\ToolDescriptionDto;
use Bitrix\AiAssistant\Definition\Tool\Contract\SubjectAwareToolContract;
use Bitrix\AiAssistant\Definition\Tool\Contract\ToolContract;
use Bitrix\AiAssistant\Definition\Tool\ToolNameFormatter;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\AiAssistant\Request\Dto\RequestSubjectDto;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Crm\V2\Public\Service\Tracking\TrackingSettingsAccess;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Mcp\Description\ToolDescriptionBuilder;
use Bitrix\Rest\V3\Mcp\Schema\ToolInputSchema;
use Bitrix\Rest\V3\Mcp\Schema\ToolSchemaBuilder;
use Bitrix\Rest\V3\Mcp\Verb\VerbRegistry;
use Bitrix\Rest\V3\Schema\MethodDescription;

final class TrackingRestToolSet extends AbstractRestToolSet
{
	private const AD_ROUTES = [
		'crm.tracking.ad.account.authorization',
		'crm.tracking.ad.account.list',
		'crm.tracking.ad.account.connect',
		'crm.tracking.ad.account.disconnect',
		'crm.tracking.ad.tree.list',
		'crm.tracking.ad.expenses.start',
		'crm.tracking.ad.expenses.getStatus',
		'crm.tracking.analytics.source.list',
	];
	private const CONNECT_TOOL_NAME = 'crm_tracking_ad_account_connect';
	private const TREE_TOOL_NAME = 'crm_tracking_ad_tree_list';
	private const SETTINGS_TOOL_NAMES = [
		'crm_tracking_ad_account_authorization',
		self::CONNECT_TOOL_NAME,
		'crm_tracking_ad_account_disconnect',
		'crm_tracking_ad_expenses_start',
	];

	public function getCode(): string
	{
		return 'crm_rest_v3_tracking';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM end-to-end analytics',
			'Read, add, and delete tracking traces of the end-to-end analytics: the source, channels,'
				. ' visited pages, and UTM tags that attribute CRM items. Read the base channels; read, create,'
				. ' set up, archive, and unarchive the sources of the traces; list, add, and delete the manual'
				. ' expenses of a source. Manage advertising account bindings, browse campaign trees, build and'
				. ' monitor existing advertising reports, and read source analytics.',
		);
	}

	public function getProviderToolDefinitionsGenerator(
		int $userId,
		?RequestSubjectDto $subject = null,
	): \Generator
	{
		foreach (parent::getProviderToolDefinitionsGenerator($userId, $subject) as $description)
		{
			yield $this->withAdWireTypes($description);
		}
		if ($userId <= 0 || (new TrackingAccess())->canRead($userId))
		{
			return;
		}

		foreach ($this->getAdvertisedAdTools() as $description)
		{
			yield $this->withAdWireTypes($description);
		}
	}

	public function getProviderToolDescription(
		string $toolName,
		int $userId,
		?RequestSubjectDto $subject = null,
	): ?ToolDescriptionDto
	{
		$description = parent::getProviderToolDescription($toolName, $userId, $subject);
		if ($description !== null || $userId <= 0 || (new TrackingAccess())->canRead($userId))
		{
			return $description === null ? null : $this->withAdWireTypes($description);
		}

		foreach ($this->getAdvertisedAdTools() as $adDescription)
		{
			if (ToolNameFormatter::format($adDescription->name) === $toolName)
			{
				return $this->withAdWireTypes($adDescription);
			}
		}

		return null;
	}

	public function getProviderToolExecutor(
		string $toolName,
		int $userId,
		?RequestSubjectDto $subject = null,
	): ToolContract|SubjectAwareToolContract
	{
		$tool = parent::getProviderToolExecutor($toolName, $userId, $subject);
		$isSettingsTool = in_array($toolName, self::SETTINGS_TOOL_NAMES, true);
		if ((!$isSettingsTool && $toolName !== self::TREE_TOOL_NAME)
			|| !$tool instanceof SubjectAwareToolContract)
		{
			return $tool;
		}

		$description = $this->getProviderToolDescription($toolName, $userId, $subject);
		if ($description === null)
		{
			return $tool;
		}

		return new TrackingAdWireTool(
			$tool,
			$description,
			ServiceLocator::getInstance()->get(TracedLogger::class),
			$isSettingsTool ? new TrackingSettingsAccess() : null,
		);
	}

	private function withAdWireTypes(ToolDescriptionDto $description): ToolDescriptionDto
	{
		if (!$description->inputSchema instanceof ToolInputSchema)
		{
			return $description;
		}

		$schema = $description->inputSchema->toArray();
		$toolName = ToolNameFormatter::format($description->name);
		if ($toolName === self::CONNECT_TOOL_NAME
			&& ($schema['properties']['clientId']['type'] ?? null) === 'string'
			&& ($schema['properties']['accountId']['type'] ?? null) === 'string')
		{
			$schema['properties']['clientId']['type'] = ['string', 'integer'];
			$schema['properties']['accountId']['type'] = ['string', 'null'];
		}
		elseif ($toolName === self::TREE_TOOL_NAME
			&& ($schema['properties']['parentId']['type'] ?? null) === 'integer')
		{
			$schema['properties']['parentId']['type'] = ['integer', 'null'];
			$schema['properties']['cursor']['properties']['field']['enum'] = ['id'];
			$schema['properties']['cursor']['properties']['order']['enum'] = ['ASC'];
		}
		else
		{
			return $description;
		}

		return new ToolDescriptionDto(
			$description->name,
			$description->description,
			new ToolInputSchema($schema),
			$description->annotations,
		);
	}

	/** @return \Generator<ToolDescriptionDto> */
	private function getAdvertisedAdTools(): \Generator
	{
		$methods = array_filter(
			$this->getAllMethodDescriptions(),
			fn(MethodDescription $method): bool => $this->isAdMethod($method),
		);
		$this->ensureGeneratedDtoClassesLoaded($methods);
		$builder = new ToolDescriptionBuilder(new VerbRegistry(), new ToolSchemaBuilder());
		foreach ($methods as $method)
		{
			$description = $builder->build($method);
			if ($description !== null)
			{
				yield $description;
			}
		}
	}

	private function isAdMethod(MethodDescription $method): bool
	{
		return $method->module === 'crm'
			&& $method->mcpTool?->toolSetCode === $this->getCode()
			&& in_array($method->actionUri, self::AD_ROUTES, true)
		;
	}
}

final class TrackingAdWireTool extends SubjectAwareToolContract
{
	public function __construct(
		private readonly SubjectAwareToolContract $tool,
		private readonly ToolDescriptionDto $description,
		TracedLogger $logger,
		private readonly ?TrackingSettingsAccess $settingsAccess,
	)
	{
		parent::__construct($logger);
	}

	public function getName(): string
	{
		return $this->tool->getName();
	}

	public function getDescription(): string
	{
		return $this->tool->getDescription();
	}

	public function getFullDescription(): ToolDescriptionDto
	{
		return $this->description;
	}

	public function getInputSchema(): array
	{
		return $this->description->inputSchema->toArray();
	}

	public function canList(int $userId, ?RequestSubjectDto $subject): bool
	{
		return $this->tool->canList($userId, $subject);
	}

	public function canRun(int $userId, ?RequestSubjectDto $subject): bool
	{
		return $this->tool->canRun($userId, $subject)
			&& ($this->settingsAccess?->canModify($userId) ?? true);
	}

	public function run(int $userId, ?RequestSubjectDto $subject, array $args = []): array
	{
		return $this->tool->run($userId, $subject, $args);
	}
}
