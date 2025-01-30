<?php

namespace Bitrix\Catalog\Grid\Filter;

use Bitrix\Catalog\ContractorTable;
use Bitrix\Main\Filter\EntityDataProvider;
use Bitrix\Main\Localization\Loc;

class contractordataprovider extends EntityDataProvider
{
    public function getSettings()
    {
        // TODO: Implement getSettings() method.
    }

    public function prepareFields()
    {
        return [
            'PERSON_TYPE' => $this->createField('PERSON_TYPE', [
                'name' => Loc::getMessage('CONTRACTOR_TYPE'),
                'type' => 'list',
                'default' => true,
                'partial' => true,
            ]),
            'PERSON_NAME' => $this->createField('PERSON_NAME', [
                'name' => Loc::getMessage('CONTRACTOR_PERSON_TITLE'),
                'default' => true,
            ]),
            'COMPANY' => $this->createField('COMPANY', [
                'name' => Loc::getMessage('CONTRACTOR_COMPANY'),
            ]),
            'PHONE' => $this->createField('PHONE', [
                'name' => Loc::getMessage('CONTRACTOR_PHONE'),
            ]),
            'EMAIL' => $this->createField('EMAIL', [
                'name' => Loc::getMessage('CONTRACTOR_EMAIL'),
            ]),
            'INN' => $this->createField('INN', [
                'name' => Loc::getMessage('CONTRACTOR_INN'),
            ]),
            'KPP' => $this->createField('KPP', [
                'name' => Loc::getMessage('CONTRACTOR_KPP'),
            ]),
        ];
    }

    public function prepareFieldData($fieldID)
    {
        if ('PERSON_TYPE' === $fieldID) {
            return ['items' => ContractorTable::getTypeDescriptions()];
        }
    }

    public function getGridColumns()
    {
        return [
            ['id' => 'ID', 'name' => 'ID', 'sort' => 'ID', 'default' => true],
            ['id' => 'PERSON_TYPE', 'name' => Loc::getMessage('CONTRACTOR_TYPE'), 'sort' => 'PERSON_TYPE', 'default' => true],
            ['id' => 'PERSON_NAME', 'name' => Loc::getMessage('CONTRACTOR_PERSON_TITLE'), 'sort' => 'PERSON_NAME', 'default' => true],
            ['id' => 'COMPANY', 'name' => Loc::getMessage('CONTRACTOR_COMPANY'), 'sort' => 'COMPANY', 'default' => true],
            ['id' => 'EMAIL', 'name' => Loc::getMessage('CONTRACTOR_EMAIL'), 'sort' => 'EMAIL', 'default' => true],
            ['id' => 'PHONE', 'name' => Loc::getMessage('CONTRACTOR_PHONE'), 'sort' => 'PHONE', 'default' => false],
            ['id' => 'POST_INDEX', 'name' => Loc::getMessage('CONTRACTOR_POST_INDEX'), 'sort' => 'POST_INDEX', 'default' => false],
            ['id' => 'INN', 'name' => Loc::getMessage('CONTRACTOR_INN'), 'sort' => 'INN', 'default' => false],
            ['id' => 'KPP', 'name' => Loc::getMessage('CONTRACTOR_KPP'), 'sort' => 'KPP', 'default' => false],
            ['id' => 'ADDRESS', 'name' => Loc::getMessage('CONTRACTOR_ADDRESS'), 'sort' => 'ADDRESS', 'default' => true],
        ];
    }

    protected function getFieldName($fieldID)
    {
        return Loc::getMessage("CONTRACTOR_{$fieldID}");
    }
}
