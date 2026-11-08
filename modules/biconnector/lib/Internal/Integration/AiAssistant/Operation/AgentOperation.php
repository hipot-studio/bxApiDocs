<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation;

/**
 * One operation of a BI tool set, as the person granting the connection reads it.
 *
 * The record describes what the set can do, never the outcome of one call: the impact is the
 * declaration of the tool, and the texts are already in the language of that person.
 */
final class AgentOperation
{
	/**
	 * @param string $toolSetCode Code the tool set class answers with.
	 * @param string $toolName Wire name of the tool.
	 * @param string $impact Value of {@see OperationImpact} declared by the tool.
	 * @param string $title Meaning of the operation.
	 * @param string|null $warning Text of the warning; null for an operation that needs none.
	 */
	public function __construct(
		public readonly string $toolSetCode,
		public readonly string $toolName,
		public readonly string $impact,
		public readonly string $title,
		public readonly ?string $warning,
	)
	{
	}
}
