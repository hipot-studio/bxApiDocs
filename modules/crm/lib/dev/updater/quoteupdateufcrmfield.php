<?php

namespace Bitrix\Crm\Dev\Updater;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Update\Stepper;

class quoteupdateufcrmfield extends Stepper
{
    protected static $moduleId = 'crm';
    protected $deleteFile = true;

    public function execute(array &$result)
    {
        if (!Loader::includeModule('crm')) {
            return false;
        }

        $className = static::class;
        $option = Option::get('crm', $className, 0);
        $result['steps'] = $option;

        $limit = 50;
        $result['steps'] = isset($result['steps']) ? $result['steps'] : 0;

        $listUfFieldsForCheck = $this->getListUfFieldsForCheck('CRM_QUOTE');
        $select = ['ID'];
        foreach ($listUfFieldsForCheck as $fieldId => $field) {
            $select[] = $field['FIELD_NAME'];
        }

        $objectQuery = \CCrmQuote::getList(
            ['ID' => 'DESC'],
            ['CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            $select,
            ['QUERY_OPTIONS' => ['LIMIT' => $limit, 'OFFSET' => $result['steps']]]
        );
        $selectedRowsCount = $objectQuery->selectedRowsCount();
        while ($entity = $objectQuery->fetch()) {
            foreach ($listUfFieldsForCheck as $fieldId => $field) {
                if (!empty($entity[$field['FIELD_NAME']])) {
                    $listValuesForUpdate = $this->prepareListValuesForUpdate($field, $entity);
                    $this->setFieldValue($field, $entity, $listValuesForUpdate);
                }
            }
        }

        if ($selectedRowsCount < $limit) {
            Option::delete('crm', ['name' => $className]);

            return false;
        }

        $result['steps'] += $selectedRowsCount;
        $option = $result['steps'];
        Option::set('crm', $className, $option);

        return true;
    }

    protected function getListUfFieldsForCheck($entityId)
    {
        $queryObject = \CUserTypeEntity::getList([], ['ENTITY_ID' => $entityId, 'USER_TYPE_ID' => 'crm']);
        $listUfFieldsForCheck = [];
        while ($listUfFields = $queryObject->fetch()) {
            if (\is_array($listUfFields['SETTINGS'])) {
                $tmpArray = array_filter($listUfFields['SETTINGS'], static function ($mark) {
                    return 'Y' === $mark;
                });
                if (1 === \count($tmpArray)) {
                    $listUfFieldsForCheck[$listUfFields['ID']] = [
                        'ENTITY_ID' => $listUfFields['ENTITY_ID'],
                        'FIELD_NAME' => $listUfFields['FIELD_NAME'],
                        'AVAILABLE_ENTITY_TYPE' => array_search('Y', $tmpArray, true),
                    ];
                }
            }
        }

        return $listUfFieldsForCheck;
    }

    protected function prepareListValuesForUpdate($field, $entity)
    {
        $ufFieldValues = $entity[$field['FIELD_NAME']];
        $listValuesForUpdate = [$field['FIELD_NAME'] => []];
        if (!empty($ufFieldValues)) {
            if (\is_array($ufFieldValues)) {
                foreach ($ufFieldValues as $fieldValue) {
                    if (!(int) $fieldValue) {
                        $explode = explode('_', $fieldValue);
                        if (\CUserTypeCrm::getLongEntityType($explode[0]) === $field['AVAILABLE_ENTITY_TYPE']) {
                            $listValuesForUpdate[$field['FIELD_NAME']][] = (int) $explode[1];
                        }
                    }
                }
            } else {
                if (!(int) $ufFieldValues) {
                    $explode = explode('_', $ufFieldValues);
                    if (\CUserTypeCrm::getLongEntityType($explode[0]) === $field['AVAILABLE_ENTITY_TYPE']) {
                        $listValuesForUpdate[$field['FIELD_NAME']] = (int) $explode[1];
                    }
                }
            }
        }

        return $listValuesForUpdate;
    }

    protected function setFieldValue($field, $entity, array $listValuesForUpdate)
    {
        global $USER_FIELD_MANAGER;
        if (!empty($listValuesForUpdate[$field['FIELD_NAME']])) {
            \CCrmEntityHelper::normalizeUserFields(
                $listValuesForUpdate,
                $field['ENTITY_ID'],
                $USER_FIELD_MANAGER,
                ['IS_NEW' => false]
            );
            $USER_FIELD_MANAGER->Update($field['ENTITY_ID'], $entity['ID'], $listValuesForUpdate);
        }
    }
}
