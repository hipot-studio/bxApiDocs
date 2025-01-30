<?php

namespace Bitrix\Calendar\Internals;

abstract class dto
{
    public function __construct(array $data = [])
    {
        $this->initComplexProperties($data);
        foreach ($data as $key => $value) {
            if ($this->checkConstructException($key, $value)) {
                continue;
            }
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * @return array|bool|float|int|string|void
     */
    public function toArray(bool $filterEmptyValue = false)
    {
        return $this->prepareValue($this, $filterEmptyValue);
    }

    protected function checkConstructException($key, $value): bool
    {
        return false;
    }

    /**
     * @return array|bool|float|int|string|void
     */
    protected function prepareValue($value, bool $filterEmptyValue)
    {
        if (\is_scalar($value)) {
            return $value;
        }

        if (\is_array($value) || \is_object($value)) {
            $result = [];
            foreach ($value as $index => $item) {
                if ($filterEmptyValue && null === $item) {
                    continue;
                }
                if ($this->checkPrepareToArrayException($index, $item)) {
                    continue;
                }
                $result[$index] = $this->prepareValue($item, $filterEmptyValue);
            }

            return $result;
        }
    }

    protected function checkPrepareToArrayException($key, $value): bool
    {
        return false;
    }

    protected function getComplexPropertyMap(): array
    {
        return [];
    }

    private function initComplexProperties(array &$data)
    {
        $map = $this->getComplexPropertyMap();
        foreach ($map as $key => $item) {
            if (!empty($item['isArray']) && !empty($data[$key]) && \is_array($data[$key])) {
                $this->{$key} = [];
                foreach ($data[$key] as $property) {
                    $this->{$key}[] = $this->prepareComplexProperty(
                        $property,
                        $item['class'],
                        $item['isMandatory'] ?? false
                    );
                }
            } elseif (empty($data[$key])) {
                $this->{$key} = null;
            } else {
                $this->{$key} = $this->prepareComplexProperty(
                    $data[$key],
                    $item['class'],
                    $item['isMandatory'] ?? false
                );
            }
            unset($data[$key]);
        }
    }

    /**
     * @return mixed
     */
    private function prepareComplexProperty(array $data, $className, $isMandatory = false)
    {
        if ($isMandatory) {
            return new $className($data);
        }

        return new $className($data ?? []);
    }
}
