<?php

namespace Bitrix\Crm\Security\Role\Manage;

class savepermissionsvalidator
{
    private array $errors = [];

    public function validate(array $data): bool
    {
        $result = true;
        if (($data['NAME'] ?? null) === null) {
            $result = false;
        }

        return $result;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getLastError(): false|string
    {
        return end($this->errors);
    }
}
