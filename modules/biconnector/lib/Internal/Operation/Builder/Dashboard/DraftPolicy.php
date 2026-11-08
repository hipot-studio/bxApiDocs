<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

/**
 * Whether an operation that edits a dashboard holds it to the draft-only rule. The entry point decides:
 * REST always enforces it, MCP only while BuilderMcpAlignment is on.
 */
enum DraftPolicy
{
	case Enforce;
	case Skip;
}
