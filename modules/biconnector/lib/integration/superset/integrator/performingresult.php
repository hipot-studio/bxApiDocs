<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\Main\Result;

final class performingresult
{
    public function __construct(
        public IntegratorResponse $response,
        public Result $requestResult,
    ) {}
}
