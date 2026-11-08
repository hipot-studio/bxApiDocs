<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Attribute;

/**
 * A builder REST action that describes the API — which methods exist and how they refuse — rather than
 * the data of the portal. The entry point does not ask whether BI Builder is available to the caller for it:
 * a caller without BI learns nothing they would not meet on the first real method. The quota still applies.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class DescribesApi
{
}
