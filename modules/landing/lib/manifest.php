<?php

namespace Bitrix\Landing;

use Bitrix\Landing\Internals\BaseTable;
use Bitrix\Main\Result;

class manifest extends BaseTable
{
    /**
     * Internal class.
     *
     * @var string
     */
    public static $internalClass = 'ManifestTable';

    /**
     * Add new record.
     *
     * @param array $fields params for add
     *
     * @return null|Result
     */
    public static function add($fields)
    {
        if (!isset($fields['CONTENT'])) {
            $fields['CONTENT'] = '';
        }
        if (!isset($fields['MANIFEST'])) {
            $fields['MANIFEST'] = [];
        }
        $fields['CONTENT'] = trim($fields['CONTENT']);

        if (isset($fields['CODE'])) {
            $res = self::getList(
                [
                    'select' => [
                        'ID', 'CONTENT', 'MANIFEST',
                    ],
                    'filter' => [
                        '=CODE' => $fields['CODE'],
                    ],
                ]
            );
            if ($row = $res->fetch()) {
                if (
                    md5($row['CONTENT']) !==
                    md5($fields['CONTENT'])
                    || md5(serialize($row['MANIFEST'])) !==
                    md5(serialize($fields['MANIFEST']))
                ) {
                    return parent::update($row['ID'], $fields);
                }

                return null;
            }
        }

        return parent::add($fields);
    }

    /**
     * Get manifest of block by code.
     *
     * @param string $code block code
     * @param bool   $full full row, not only manifest
     *
     * @return array
     */
    public static function getByCode($code, $full = false)
    {
        static $manifests = [];

        if (!isset($manifests[$code])) {
            $res = self::getList([
                'select' => [
                    'MANIFEST', 'CONTENT', 'DATE_MODIFY',
                ],
                'filter' => [
                    '=CODE' => trim($code),
                ],
            ]);
            if ($row = $res->fetch()) {
                $row['MANIFEST']['timestamp'] = $row['DATE_MODIFY']->getTimeStamp();
                $manifests[$code] = $row;
            } else {
                $manifests[$code] = [];
            }
        }

        return $full ? $manifests[$code] : $manifests[$code]['MANIFEST'];
    }
}
