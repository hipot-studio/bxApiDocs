<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Dto;

final class usercredentials
{
    public function __construct(
        public string $login,
        public string $password,
    ) {}
}
