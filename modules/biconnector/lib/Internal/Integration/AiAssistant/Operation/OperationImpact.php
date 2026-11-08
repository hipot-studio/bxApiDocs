<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation;

/**
 * How far a BI tool call reaches: the single declaration of an operation's
 * impact, made by the tool itself and read by everything that needs it — the
 * MCP annotations of the tool description, the operation catalogue and the
 * destructive budget.
 *
 * `Destructive` means the call cannot be undone: it removes an object or
 * revokes access already handed out.
 */
enum OperationImpact: string
{
	case Read = 'read';
	case Modify = 'modify';
	case Destructive = 'destructive';

	/**
	 * Maps the impact onto the standard MCP tool hints. `idempotentHint` is not
	 * derived here: repeatability is a property of the call, not of its impact.
	 *
	 * `destructiveHint` is stated only where it is true: an explicit `false`
	 * claims the call only adds, which is not so for a modification that
	 * overwrites the previous state.
	 */
	public function toAnnotations(): object
	{
		return (object)match ($this)
		{
			self::Read => ['readOnlyHint' => true],
			self::Modify => ['readOnlyHint' => false],
			self::Destructive => ['readOnlyHint' => false, 'destructiveHint' => true],
		};
	}
}
