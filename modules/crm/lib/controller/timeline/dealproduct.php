<?php

namespace Bitrix\Crm\Controller\Timeline;

use Bitrix\Catalog;
use Bitrix\Main;
use Bitrix\Main\Engine\Controller;

class dealproduct extends Controller
{
    public function addToDealAction(int $dealId, int $productId, array $options = [])
    {
        if (!\CCrmDeal::CheckUpdatePermission($dealId, \CCrmPerms::GetCurrentUserPermissions())) {
            return;
        }

        $productField = null;
        if ($productId > 0 && Main\Loader::includeModule('catalog')) {
            $productField = Catalog\ProductTable::getRow([
                'select' => [
                    'PRODUCT_NAME' => 'IBLOCK_ELEMENT.NAME',
                    'TYPE',
                ],
                'filter' => [
                    '=ID' => $productId,
                ],
            ]);
        }

        $row = [
            'PRODUCT_ID' => $productId,
            'QUANTITY' => 1,
        ];

        if ($productField) {
            $row = array_merge($row, $productField);
        }

        if (isset($options['price'])) {
            $price = $options['price'];

            $row = array_merge(
                $row,
                [
                    'PRICE' => $price,
                    'PRICE_ACCOUNT' => $price,
                    'PRICE_EXCLUSIVE' => $price,
                    'PRICE_NETTO' => $price,
                    'PRICE_BRUTTO' => $price,
                ]
            );
        }

        \CCrmDeal::addProductRows($dealId, [$row]);
    }
}
