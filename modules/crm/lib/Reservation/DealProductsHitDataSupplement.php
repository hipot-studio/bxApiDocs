<?php

namespace Bitrix\Crm\Reservation;

use Bitrix\Crm\Service\Sale\Reservation\ReservationService;
use Bitrix\Main\Type\Date;

class DealProductsHitDataSupplement
{
    /** @var array */
    private $data = [];

    /** @var DealProductsHitDataSupplement */
    private static $instance;

    /**
     * DealProductsHitDataSupplement constructor.
     */
    private function __construct() {}

    public static function getInstance(): self
    {
        if (null === static::$instance) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    public function setProductRows(int $dealId, array $productRows): void
    {
        $this->data[$dealId] = [
            'PRODUCT_ROWS' => $productRows,
        ];
    }

    public function readDealSaveData(int $dealId)
    {
        $this->data[$dealId] = [
            'SAFE_ORIGINAL_ROWS' => \CCrmProductRow::getOriginalRows(),
        ];
    }

    public function getSupplementedProductRows(int $dealId): ?array
    {
        if (!isset($this->data[$dealId])) {
            return null;
        }
        $dealData = $this->data[$dealId];

        if (isset($dealData['SAFE_ORIGINAL_ROWS'])) {
            $result = [];

            foreach ($dealData['SAFE_ORIGINAL_ROWS'] as $safeOriginalRow) {
                $enrichedProductRow = $safeOriginalRow['ORIGINAL_ROW'];
                $enrichedProductRow['ID'] = $safeOriginalRow['ID'];
                $result[] = $enrichedProductRow;
            }

            return $result;
        }

        if (isset($dealData['PRODUCT_ROWS']) && !empty($dealData['PRODUCT_ROWS'])) {
            return $dealData['PRODUCT_ROWS'];
        }

        return null;
    }

    /**
     * Save reserve data sorted by ORIGINAL_ROW in supplement.
     */
    public function saveSupplementReserveData(int $dealId): void
    {
        $productRows = $this->getSupplementedProductRows($dealId);
        if (!$productRows) {
            return;
        }

        $service = ReservationService::getInstance();
        foreach ($productRows as $row) {
            if (!isset($row['INPUT_RESERVE_QUANTITY'])) {
                // regardless of the quantity, save it to remember the selected store.
                $row['INPUT_RESERVE_QUANTITY'] = 0;
            }

            $dateEndReserve = null;
            if (!empty($row['DATE_RESERVE_END'])) {
                $dateEndReserve = Date::createFromText($row['DATE_RESERVE_END']);
            }

            $service->reservationProductRow(
                $row['ID'],
                $row['INPUT_RESERVE_QUANTITY'],
                $row['STORE_ID'] ?? null,
                $dateEndReserve
            );
        }
    }
}
