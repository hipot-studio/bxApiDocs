<?php

namespace Bitrix\Conversion;

class splitreportcontext extends ReportContext
{
    public function getRates(array $splits, array $rateClasses, array $filter = [], $step = null)
    {
        $filter['=NAME'] = RateManager::getCounters($rateClasses);

        $splitCounters = $this->getCounters($splits, $filter, $step);

        $result = [];

        foreach ($splitCounters as $split => $counters) {
            $result[$split] = parent::getRatesCommon($rateClasses, $counters, $step);
        }

        return $result;
    }

    protected function getCounters(array $splits, array $filter = [], $step = null)
    {
        $result = [];

        $totalCounters = parent::getCounters($filter, $step);
        $otherCounters = $totalCounters;

        foreach ($splits as $splitKey => $attribute) {
            if ($attribute['NAME']) {
                $this->setAttribute($attribute['NAME'], $attribute['VALUE']);

                $counters = parent::getCounters($filter, $step);

                $result[$splitKey] = $counters;

                self::subtructCounters($otherCounters, $counters);

                $this->unsetAttribute($attribute['NAME'], $attribute['VALUE']);
            }
        }

        $result['other'] = $otherCounters;
        $result['total'] = $totalCounters;

        return $result;
    }

    private static function subtructCounters(array &$one, array $two)
    {
        // TODO first STEPS than loop

        foreach ($one as $key => &$value) {
            if ('STEPS' === $key) {
                if ($twoSteps = $two['STEPS']) {
                    self::subtructDayCounters($value, $twoSteps);
                }
            } else {
                $value -= $two[$key];
            }
        }
    }

    private static function subtructDayCounters(array &$one, array $two)
    {
        foreach ($one as $day => &$oneDay) {
            if ($twoDay = $two[$day]) {
                self::subtructCounters($oneDay, $twoDay, false);
            }
        }
    }
}
