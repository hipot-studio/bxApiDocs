<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Attribute;

/**
 * A builder REST action that talks to the BI instance. Once the quota and the prefilters let the call through,
 * the entry point starts the instance or checks its status for such an action, as an MCP tool does before it runs.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RequiresSuperset
{
}
