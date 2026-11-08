<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation;

use Bitrix\Main\Loader;

/**
 * Enumerating part of a tool set description, read by the agent and not by a person.
 *
 * The handwritten preamble routing the agent to the set stays with the set; everything the set
 * can do comes from the catalogue, so an operation is declared once and the description cannot
 * drift away from the list shown to whoever grants the connection.
 */
final class AgentOperationsDescription
{
	private const OPERATIONS_HEADER = 'Operations in this set:';

	/**
	 * The description of a set is assembled in one fixed language. It joins a handwritten English
	 * preamble, it is read by the agent and not by a person, and it is kept in a registry shared by
	 * the whole portal — so a wording taken from the language of whoever happened to build it first
	 * would then be served to everybody else.
	 */
	private const DESCRIPTION_LANGUAGE = 'en';

	private ?AgentOperationCatalog $catalog = null;

	/**
	 * One line per operation, with its impact and its consequences.
	 *
	 * Wording comes in the language of the description, not of the request: see
	 * self::DESCRIPTION_LANGUAGE. Records shown to a person keep the language of the request.
	 *
	 * @param string $toolSetCode Code the tool set class answers with.
	 * @return string Empty string for a set with no known operations, so that its preamble stands
	 * alone instead of carrying a dangling header.
	 */
	public function getForToolSet(string $toolSetCode): string
	{
		if (!$this->isCatalogAvailable())
		{
			return '';
		}

		$lines = [];
		foreach ($this->getCatalog()->getOperations($toolSetCode, self::DESCRIPTION_LANGUAGE) as $operation)
		{
			$lines[] = $this->describeOperation($operation);
		}

		if (!$lines)
		{
			return '';
		}

		return "\n\n" . self::OPERATIONS_HEADER . "\n" . implode("\n", $lines);
	}

	private function describeOperation(AgentOperation $operation): string
	{
		$line = "- {$operation->toolName} [{$operation->impact}]: {$operation->title}";

		return $operation->warning === null ? $line : "{$line}. {$operation->warning}";
	}

	private function getCatalog(): AgentOperationCatalog
	{
		return $this->catalog ??= new AgentOperationCatalog();
	}

	/**
	 * The catalogue reads name and impact off the tool classes, which the module of the agent
	 * declares, so it cannot be read before that module is included.
	 */
	private function isCatalogAvailable(): bool
	{
		return Loader::includeModule('aiassistant');
	}
}
