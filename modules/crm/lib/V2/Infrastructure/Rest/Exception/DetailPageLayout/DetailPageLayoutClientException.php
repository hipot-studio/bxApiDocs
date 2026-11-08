<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\DetailPageLayout;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

final class DetailPageLayoutClientException extends DetailPageLayoutException implements SkipWriteToLogException
{
}
