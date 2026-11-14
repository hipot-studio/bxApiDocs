<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet\Summary;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Access\SummaryAvailability;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Communications\GetCommunications\GetCommunicationsTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Summary\GetSummary\GetSummaryTool;
use Bitrix\Main\Loader;

Loader::requireModule('aiassistant');

/**
 * The tools of CRM that answer about one entity, as the registry of definitions sees them.
 *
 * Whether the user is granted them at all is answered by {@see SummaryAvailability}, the same measure
 * the tool and the agent ask: the set is asked only when a client names it, so a measure of its own
 * would let the tool through wherever the set is already hidden.
 *
 * The question asked here is the narrow one, without the switch of the portal. The set is asked before
 * the tool is, so a refusal of the switch at this step would take the tool off the catalogue instead of
 * letting it answer why the summary is off.
 */
final class SummaryToolSet extends BaseToolSet
{
	/**
	 * The tools of the set. The agent definition refers to this list instead of naming the classes anew.
	 */
	public const TOOLS = [
		GetSummaryTool::class,
		GetCommunicationsTool::class,
	];

	public function __construct(
		private readonly SummaryAvailability $availability = new SummaryAvailability(),
	)
	{
	}

	public function getCode(): string
	{
		return 'crm_summary';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM entity summary and communication history',
			'Tools that return structured data about one CRM entity: a contact, a company or a deal.'
				. ' One of them answers a summary of the entity - what it is, what happened with it and what'
				. ' deserves attention; the other answers the history of the communication with it - the'
				. ' calls, the letters and the dialogues of the open lines - with what the speech analysis'
				. ' had to say about every conversation. Which parts the data carries depends on the type of'
				. ' the entity asked about.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto(self::TOOLS);
	}

	public function canList(int $userId): bool
	{
		return $this->availability->isGrantedTo($userId);
	}

	public function canRun(int $userId): bool
	{
		return $this->availability->isGrantedTo($userId);
	}
}
