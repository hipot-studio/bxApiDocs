<?php

namespace Bitrix\Calendar\Internals;

/**
 * Object for saving statuses of other objects/
 * See also ObjectStatusTrait.
 */
class objectstatus
{
    /** @var array */
    private $errors = [];

    public function isSuccess(): bool
    {
        return empty($this->errors);
    }

    public function hasErrors(): bool
    {
        return !$this->isSuccess();
    }

    public function addError(string $code, string $message)
    {
        $this->errors[] = [
            'code' => $code,
            'message' => $message,
        ];
    }

    public function resetErrors()
    {
        $this->errors = [];
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getErrorsByCode(string $code): array
    {
        if (!$this->hasErrors()) {
            return [];
        }

        return array_filter($this->errors, static function ($error) use ($code) {
            return $error['code'] === $code;
        });
    }

    public function getErrorByCode(string $code): array
    {
        if ($filtredErrors = $this->getErrorsByCode($code)) {
            return end($filtredErrors);
        }

        return [];
    }
}
