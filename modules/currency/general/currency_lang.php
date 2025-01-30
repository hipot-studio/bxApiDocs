<?php

use Bitrix\Currency;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

/** @deprecated */
class CAllCurrencyLang {}

class CCurrencyLang extends CAllCurrencyLang
{
    /** @deprecated */
    public const SEP_EMPTY = Currency\CurrencyClassifier::SEPARATOR_EMPTY;

    /** @deprecated */
    public const SEP_DOT = Currency\CurrencyClassifier::SEPARATOR_DOT;

    /** @deprecated */
    public const SEP_COMMA = Currency\CurrencyClassifier::SEPARATOR_COMMA;

    /** @deprecated */
    public const SEP_SPACE = Currency\CurrencyClassifier::SEPARATOR_SPACE;

    /** @deprecated */
    public const SEP_NBSPACE = Currency\CurrencyClassifier::SEPARATOR_NBSPACE;

    protected static array $arSeparators = [
        Currency\CurrencyClassifier::SEPARATOR_EMPTY => '',
        Currency\CurrencyClassifier::SEPARATOR_DOT => '.',
        Currency\CurrencyClassifier::SEPARATOR_COMMA => ',',
        Currency\CurrencyClassifier::SEPARATOR_SPACE => ' ',
        Currency\CurrencyClassifier::SEPARATOR_NBSPACE => '&nbsp;',
    ];

    protected static array $arDefaultValues = [
        'FORMAT_STRING' => '#',
        'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_DOT,
        'THOUSANDS_SEP' => ' ',
        'DECIMALS' => 2,
        'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_SPACE,
        'HIDE_ZERO' => 'N',
    ];

    protected static array $arCurrencyFormat = [];

    protected static int $useHideZero = 0;

    public static function enableUseHideZero()
    {
        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
            return;
        }
        ++self::$useHideZero;
    }

    public static function disableUseHideZero()
    {
        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
            return;
        }
        --self::$useHideZero;
    }

    public static function isAllowUseHideZero(): bool
    {
        return !(defined('ADMIN_SECTION') && ADMIN_SECTION === true) && self::$useHideZero >= 0;
    }

    public static function checkFields($action, &$fields, $currency = '', $language = '', $getErrors = false)
    {
        global $DB, $USER, $APPLICATION;

        $getErrors = (true === $getErrors);
        $action = mb_strtoupper($action);
        if ('ADD' !== $action && 'UPDATE' !== $action) {
            return false;
        }
        if (!is_array($fields)) {
            return false;
        }
        if ('ADD' === $action) {
            if (isset($fields['CURRENCY'])) {
                $currency = $fields['CURRENCY'];
            }
            if (isset($fields['LID'])) {
                $language = $fields['LID'];
            }
        }
        $currency = Currency\CurrencyManager::checkCurrencyID($currency);
        $language = Currency\CurrencyManager::checkLanguage($language);
        if (false === $currency || false === $language) {
            return false;
        }

        $errorMessages = [];

        $clearFields = [
            '~CURRENCY',
            '~LID',
            'TIMESTAMP_X',
            'DATE_CREATE',
            '~DATE_CREATE',
            '~MODIFIED_BY',
            '~CREATED_BY',
            '~FORMAT_STRING',
            '~FULL_NAME',
            '~DEC_POINT',
            '~THOUSANDS_SEP',
            '~DECIMALS',
            '~THOUSANDS_VARIANT',
            '~HIDE_ZERO',
        ];
        if ('UPDATE' === $action) {
            $clearFields[] = 'CREATED_BY';
            $clearFields[] = 'CURRENCY';
            $clearFields[] = 'LID';
        }
        $fields = array_filter($fields, 'CCurrencyLang::clearFields');
        foreach ($clearFields as $fieldName) {
            if (isset($fields[$fieldName])) {
                unset($fields[$fieldName]);
            }
        }
        unset($fieldName, $clearFields);

        if ('ADD' === $action) {
            $defaultValues = self::$arDefaultValues;
            unset($defaultValues['FORMAT_STRING']);

            $fields = array_merge($defaultValues, $fields);
            unset($defaultValues);

            if (empty($fields['FORMAT_STRING'])) {
                $errorMessages[] = [
                    'id' => 'FORMAT_STRING', 'text' => Loc::getMessage('BT_CUR_LANG_ERR_FORMAT_STRING_IS_EMPTY', ['#LANG#' => $language]),
                ];
            }

            if (empty($errorMessages)) {
                $fields['CURRENCY'] = $currency;
                $fields['LID'] = $language;
            }
        }

        if (empty($errorMessages)) {
            if (isset($fields['FORMAT_STRING']) && empty($fields['FORMAT_STRING'])) {
                $errorMessages[] = [
                    'id' => 'FORMAT_STRING', 'text' => Loc::getMessage('BT_CUR_LANG_ERR_FORMAT_STRING_IS_EMPTY', ['#LANG#' => $language]),
                ];
            }
            if (isset($fields['DECIMALS'])) {
                $fields['DECIMALS'] = (int) $fields['DECIMALS'];
                if ($fields['DECIMALS'] < 0) {
                    $fields['DECIMALS'] = self::$arDefaultValues['DECIMALS'];
                }
            }
            $validateCustomSeparator = false;
            if (isset($fields['THOUSANDS_VARIANT'])) {
                if (empty($fields['THOUSANDS_VARIANT']) || !isset(self::$arSeparators[$fields['THOUSANDS_VARIANT']])) {
                    $fields['THOUSANDS_VARIANT'] = false;
                    $validateCustomSeparator = true;
                } else {
                    $fields['THOUSANDS_SEP'] = self::$arSeparators[$fields['THOUSANDS_VARIANT']];
                }
            } else {
                if (isset($fields['THOUSANDS_SEP'])) {
                    $validateCustomSeparator = true;
                }
            }

            if ($validateCustomSeparator) {
                if (!isset($fields['THOUSANDS_SEP']) || '' === $fields['THOUSANDS_SEP']) {
                    $errorMessages[] = [
                        'id' => 'THOUSANDS_SEP',
                        'text' => Loc::getMessage(
                            'BT_CUR_LANG_ERR_THOUSANDS_SEP_IS_EMPTY',
                            ['#LANG#' => $language]
                        ),
                    ];
                } else {
                    if (!preg_match('/^&(#[x]?[0-9a-zA-Z]+|[a-zA-Z]+);$/', $fields['THOUSANDS_SEP'])) {
                        $errorMessages[] = [
                            'id' => 'THOUSANDS_SEP',
                            'text' => Loc::getMessage(
                                'BT_CUR_LANG_ERR_THOUSANDS_SEP_IS_NOT_VALID',
                                ['#LANG#' => $language]
                            ),
                        ];
                    }
                }
            }
            unset($validateCustomSeparator);

            if (isset($fields['HIDE_ZERO'])) {
                $fields['HIDE_ZERO'] = ('Y' === $fields['HIDE_ZERO'] ? 'Y' : 'N');
            }
        }
        $intUserID = 0;
        $boolUserExist = CCurrency::isUserExists();
        if ($boolUserExist) {
            $intUserID = (int) $USER->GetID();
        }
        $strDateFunction = $DB->GetNowFunction();
        $fields['~TIMESTAMP_X'] = $strDateFunction;
        if ($boolUserExist) {
            if (!isset($fields['MODIFIED_BY'])) {
                $fields['MODIFIED_BY'] = $intUserID;
            }
            $fields['MODIFIED_BY'] = (int) $fields['MODIFIED_BY'];
            if ($fields['MODIFIED_BY'] <= 0) {
                $fields['MODIFIED_BY'] = $intUserID;
            }
        }
        if ('ADD' === $action) {
            $fields['~DATE_CREATE'] = $strDateFunction;
            if ($boolUserExist) {
                if (!isset($arFields['CREATED_BY'])) {
                    $fields['CREATED_BY'] = $intUserID;
                }
                $fields['CREATED_BY'] = (int) $fields['CREATED_BY'];
                if ($fields['CREATED_BY'] <= 0) {
                    $fields['CREATED_BY'] = $intUserID;
                }
            }
        }

        if (empty($errorMessages)) {
            if ('ADD' === $action) {
                if (!empty($fields['THOUSANDS_VARIANT']) && isset(self::$arSeparators[$fields['THOUSANDS_VARIANT']])) {
                    if ($fields['DEC_POINT'] === self::$arSeparators[$fields['THOUSANDS_VARIANT']]) {
                        $errorMessages[] = [
                            'id' => 'DEC_POINT',
                            'text' => Loc::getMessage(
                                'BT_CUR_LANG_ERR_DEC_POINT_EQUAL_THOUSANDS_SEP',
                                ['#LANG#' => $language]
                            ),
                        ];
                    }
                }
            } else {
                if (
                    isset($fields['DEC_POINT'])
                    || (isset($fields['THOUSANDS_VARIANT'], self::$arSeparators[$fields['THOUSANDS_VARIANT']]))
                ) {
                    $copyFields = $fields;
                    $needFields = [];
                    if (!isset($copyFields['DEC_POINT'])) {
                        $needFields[] = 'DEC_POINT';
                    }
                    if (!isset($copyFields['THOUSANDS_VARIANT'])) {
                        $needFields[] = 'THOUSANDS_VARIANT';
                    }

                    if (!empty($needFields)) {
                        $row = Currency\CurrencyLangTable::getList([
                            'select' => $needFields,
                            'filter' => ['=CURRENCY' => $currency, '=LID' => $language],
                        ])->fetch();
                        if (!empty($row)) {
                            $copyFields = array_merge($copyFields, $row);
                            $needFields = [];
                        }
                        unset($row);
                    }
                    if (
                        empty($needFields)
                        && (!empty($copyFields['THOUSANDS_VARIANT']) && isset(self::$arSeparators[$copyFields['THOUSANDS_VARIANT']]))
                        && ($copyFields['DEC_POINT'] === self::$arSeparators[$copyFields['THOUSANDS_VARIANT']])
                    ) {
                        $errorMessages[] = [
                            'id' => 'DEC_POINT',
                            'text' => Loc::getMessage(
                                'BT_CUR_LANG_ERR_DEC_POINT_EQUAL_THOUSANDS_SEP',
                                ['#LANG#' => $language]
                            ),
                        ];
                    }
                    unset($needFields, $copyFields);
                }
            }
        }

        if (!empty($errorMessages)) {
            if ($getErrors) {
                return $errorMessages;
            }

            $obError = new CAdminException($errorMessages);
            $APPLICATION->ResetException();
            $APPLICATION->ThrowException($obError);

            return false;
        }

        return true;
    }

    public static function Add($arFields)
    {
        global $DB;

        if (!self::checkFields('ADD', $arFields)) {
            return false;
        }

        $arInsert = $DB->PrepareInsert('b_catalog_currency_lang', $arFields);

        $strSql = 'insert into b_catalog_currency_lang('.$arInsert[0].') values('.$arInsert[1].')';
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        Currency\CurrencyManager::clearCurrencyCache($arFields['LID']);
        Currency\CurrencyLangTable::getEntity()->cleanCache();

        return true;
    }

    public static function Update($currency, $lang, $arFields)
    {
        global $DB;

        $currency = Currency\CurrencyManager::checkCurrencyID($currency);
        $lang = Currency\CurrencyManager::checkLanguage($lang);
        if (false === $currency || false === $lang) {
            return false;
        }

        if (!self::checkFields('UPDATE', $arFields, $currency, $lang)) {
            return false;
        }

        $strUpdate = $DB->PrepareUpdate('b_catalog_currency_lang', $arFields);
        if (!empty($strUpdate)) {
            $strSql = 'update b_catalog_currency_lang set '.$strUpdate." where CURRENCY = '".$DB->ForSql($currency)."' and LID='".$DB->ForSql($lang)."'";
            $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

            Currency\CurrencyManager::clearCurrencyCache($lang);
            Currency\CurrencyLangTable::getEntity()->cleanCache();
        }

        return true;
    }

    public static function Delete($currency, $lang)
    {
        global $DB;

        $currency = Currency\CurrencyManager::checkCurrencyID($currency);
        $lang = Currency\CurrencyManager::checkLanguage($lang);
        if (false === $currency || false === $lang) {
            return false;
        }

        Currency\CurrencyManager::clearCurrencyCache($lang);
        Currency\CurrencyLangTable::getEntity()->cleanCache();

        $strSql = "delete from b_catalog_currency_lang where CURRENCY = '".$DB->ForSql($currency)."' and LID = '".$DB->ForSql($lang)."'";
        $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        return true;
    }

    public static function GetByID($currency, $lang)
    {
        global $DB;

        $currency = Currency\CurrencyManager::checkCurrencyID($currency);
        $lang = Currency\CurrencyManager::checkLanguage($lang);
        if (false === $currency || false === $lang) {
            return false;
        }

        $strSql = "select * from b_catalog_currency_lang where CURRENCY = '".$DB->ForSql($currency)."' and LID = '".$DB->ForSql($lang)."'";
        $db_res = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);

        if ($res = $db_res->Fetch()) {
            return $res;
        }

        return false;
    }

    public static function GetCurrencyFormat($currency, $lang = LANGUAGE_ID)
    {
        // @global CStackCacheManager $stackCacheManager
        global $stackCacheManager;

        if (defined('CURRENCY_SKIP_CACHE') && CURRENCY_SKIP_CACHE) {
            $arCurrencyLang = self::GetByID($currency, $lang);
        } else {
            $cacheTime = CURRENCY_CACHE_DEFAULT_TIME;
            if (defined('CURRENCY_CACHE_TIME')) {
                $cacheTime = (int) CURRENCY_CACHE_TIME;
            }

            $strCacheKey = $currency.'_'.$lang;

            $stackCacheManager->SetLength('currency_currency_lang', 20);
            $stackCacheManager->SetTTL('currency_currency_lang', $cacheTime);
            if ($stackCacheManager->Exist('currency_currency_lang', $strCacheKey)) {
                $arCurrencyLang = $stackCacheManager->Get('currency_currency_lang', $strCacheKey);
            } else {
                $arCurrencyLang = self::GetByID($currency, $lang);
                $stackCacheManager->Set('currency_currency_lang', $strCacheKey, $arCurrencyLang);
            }
        }

        return $arCurrencyLang;
    }

    public static function GetList($by = 'lang', $order = 'asc', $currency = '')
    {
        global $DB;

        $strSql = 'select CURL.* from b_catalog_currency_lang CURL ';

        if ('' !== $currency) {
            $strSql .= "where CURL.CURRENCY = '".$DB->ForSql($currency, 3)."' ";
        }

        if ('currency' === strtolower($by)) {
            $strSqlOrder = ' order by CURL.CURRENCY ';
        } elseif ('name' === strtolower($by)) {
            $strSqlOrder = ' order by CURL.FULL_NAME ';
        } else {
            $strSqlOrder = ' order BY CURL.LID ';
        }

        if ('desc' === $order) {
            $strSqlOrder .= ' desc ';
        }

        $strSql .= $strSqlOrder;

        return $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
    }

    public static function GetDefaultValues(): array
    {
        return self::$arDefaultValues;
    }

    public static function GetSeparators(): array
    {
        return self::$arSeparators;
    }

    public static function GetSeparatorTypes($boolFull = false): array
    {
        $boolFull = (true === $boolFull);
        if ($boolFull) {
            return [
                Currency\CurrencyClassifier::SEPARATOR_EMPTY => Loc::getMessage('BT_CUR_LANG_SEP_VARIANT_EMPTY'),
                Currency\CurrencyClassifier::SEPARATOR_DOT => Loc::getMessage('BT_CUR_LANG_SEP_VARIANT_DOT'),
                Currency\CurrencyClassifier::SEPARATOR_COMMA => Loc::getMessage('BT_CUR_LANG_SEP_VARIANT_COMMA'),
                Currency\CurrencyClassifier::SEPARATOR_SPACE => Loc::getMessage('BT_CUR_LANG_SEP_VARIANT_SPACE'),
                Currency\CurrencyClassifier::SEPARATOR_NBSPACE => Loc::getMessage('BT_CUR_LANG_SEP_VARIANT_NBSPACE'),
            ];
        }

        return [
            Currency\CurrencyClassifier::SEPARATOR_EMPTY,
            Currency\CurrencyClassifier::SEPARATOR_DOT,
            Currency\CurrencyClassifier::SEPARATOR_COMMA,
            Currency\CurrencyClassifier::SEPARATOR_SPACE,
            Currency\CurrencyClassifier::SEPARATOR_NBSPACE,
        ];
    }

    public static function GetFormatTemplates(): array
    {
        $installCurrencies = Currency\CurrencyManager::getInstalledCurrencies();
        $templates = [];
        $templates[] = [
            'TEXT' => '$1.234,10',
            'FORMAT' => '$#',
            'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
            'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_DOT,
            'DECIMALS' => '2',
        ];
        $templates[] = [
            'TEXT' => '$1 234,10',
            'FORMAT' => '$#',
            'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
            'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_SPACE,
            'DECIMALS' => '2',
        ];
        $templates[] = [
            'TEXT' => '1.234,10 USD',
            'FORMAT' => '# USD',
            'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
            'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_DOT,
            'DECIMALS' => '2',
        ];
        $templates[] = [
            'TEXT' => '1 234,10 USD',
            'FORMAT' => '# USD',
            'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
            'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_SPACE,
            'DECIMALS' => '2',
        ];
        $templates[] = [
            'TEXT' => '&euro;2.345,20',
            'FORMAT' => '&euro;#',
            'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
            'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_DOT,
            'DECIMALS' => '2',
        ];
        $templates[] = [
            'TEXT' => '&euro;2 345,20',
            'FORMAT' => '&euro;#',
            'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
            'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_SPACE,
            'DECIMALS' => '2',
        ];
        $templates[] = [
            'TEXT' => '2.345,20 EUR',
            'FORMAT' => '# EUR',
            'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
            'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_DOT,
            'DECIMALS' => '2',
        ];
        $templates[] = [
            'TEXT' => '2 345,20 EUR',
            'FORMAT' => '# EUR',
            'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
            'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_SPACE,
            'DECIMALS' => '2',
        ];

        if (in_array('RUB', $installCurrencies, true)) {
            $rubTitle = Loc::getMessage('BT_CUR_LANG_CURRENCY_RUBLE');
            $templates[] = [
                'TEXT' => '3.456,70 '.$rubTitle,
                'FORMAT' => '# '.$rubTitle,
                'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
                'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_DOT,
                'DECIMALS' => '2',
            ];
            $templates[] = [
                'TEXT' => '3 456,70 '.$rubTitle,
                'FORMAT' => '# '.$rubTitle,
                'DEC_POINT' => Currency\CurrencyClassifier::DECIMAL_POINT_COMMA,
                'THOUSANDS_VARIANT' => Currency\CurrencyClassifier::SEPARATOR_SPACE,
                'DECIMALS' => '2',
            ];
        }

        return $templates;
    }

    public static function GetFormatDescription($currency)
    {
        $safeFormat = (
            Main\Context::getCurrent()->getRequest()->isAdminSection()
            || ModuleManager::isModuleInstalled('bitrix24')
        );
        $currency = (string) $currency;

        if (!isset(self::$arCurrencyFormat[$currency])) {
            $arCurFormat = self::GetCurrencyFormat($currency);
            if (false === $arCurFormat) {
                $arCurFormat = self::$arDefaultValues;
                $arCurFormat['FULL_NAME'] = $currency;
            } else {
                if (!isset($arCurFormat['DECIMALS'])) {
                    $arCurFormat['DECIMALS'] = self::$arDefaultValues['DECIMALS'];
                }
                $arCurFormat['DECIMALS'] = (int) $arCurFormat['DECIMALS'];
                if (!isset($arCurFormat['DEC_POINT'])) {
                    $arCurFormat['DEC_POINT'] = self::$arDefaultValues['DEC_POINT'];
                }
                if (!empty($arCurFormat['THOUSANDS_VARIANT']) && isset(self::$arSeparators[$arCurFormat['THOUSANDS_VARIANT']])) {
                    $arCurFormat['THOUSANDS_SEP'] = self::$arSeparators[$arCurFormat['THOUSANDS_VARIANT']];
                } elseif (!isset($arCurFormat['THOUSANDS_SEP'])) {
                    $arCurFormat['THOUSANDS_SEP'] = self::$arDefaultValues['THOUSANDS_SEP'];
                }
                if (!isset($arCurFormat['FORMAT_STRING'])) {
                    $arCurFormat['FORMAT_STRING'] = self::$arDefaultValues['FORMAT_STRING'];
                }

                $sanitizer = new CBXSanitizer();
                $sanitizer->setLevel(CBXSanitizer::SECURE_LEVEL_LOW);
                $sanitizer->ApplyDoubleEncode(false);
                $arCurFormat['FORMAT_STRING'] = $sanitizer->SanitizeHtml($arCurFormat['FORMAT_STRING']);
                unset($sanitizer);

                if ($safeFormat) {
                    $arCurFormat['FORMAT_STRING'] = strip_tags(preg_replace(
                        '#<script[^>]*?>.*?</script[^>]*?>#is',
                        '',
                        $arCurFormat['FORMAT_STRING']
                    ));
                }
                if (empty($arCurFormat['HIDE_ZERO'])) {
                    $arCurFormat['HIDE_ZERO'] = self::$arDefaultValues['HIDE_ZERO'];
                }
            }

            $arCurFormat['TEMPLATE'] = [
                'SINGLE' => $arCurFormat['FORMAT_STRING'],
                'PARTS' => [
                    0 => $arCurFormat['FORMAT_STRING'],
                ],
                'VALUE_INDEX' => 0,
            ];
            $parts = static::explodeFormatTemplate($arCurFormat['FORMAT_STRING']);
            if (!empty($parts)) {
                $arCurFormat['TEMPLATE']['PARTS'] = $parts;
                $arCurFormat['TEMPLATE']['VALUE_INDEX'] = (int) array_search('#', $parts, true);
            }
            unset($parts);

            self::$arCurrencyFormat[$currency] = $arCurFormat;
        } else {
            $arCurFormat = self::$arCurrencyFormat[$currency];
        }

        return $arCurFormat;
    }

    public static function CurrencyFormat($price, $currency, $useTemplate = true)
    {
        static $eventExists = null;

        $useTemplate = (bool) $useTemplate;
        if ($useTemplate) {
            if (true === $eventExists || null === $eventExists) {
                foreach (GetModuleEvents('currency', 'CurrencyFormat', true) as $arEvent) {
                    $eventExists = true;
                    $result = ExecuteModuleEventEx($arEvent, [$price, $currency]);
                    if ('' !== $result) {
                        return $result;
                    }
                }
                if (null === $eventExists) {
                    $eventExists = false;
                }
            }
        }

        if (!isset($price) || '' === $price) {
            return '';
        }

        $currency = Currency\CurrencyManager::checkCurrencyID($currency);
        if (false === $currency) {
            return '';
        }

        $format = self::$arCurrencyFormat[$currency] ?? self::GetFormatDescription($currency);

        return self::formatValue($price, $format, $useTemplate);
    }

    public static function formatValue($value, array $format, $useTemplate = true)
    {
        $value = (float) $value;
        $decimals = $format['DECIMALS'];
        if (self::isAllowUseHideZero() && 'Y' === $format['HIDE_ZERO']) {
            if (round($value, $format['DECIMALS']) === round($value, 0)) {
                $decimals = 0;
            }
        }
        $result = number_format($value, $decimals, $format['DEC_POINT'], $format['THOUSANDS_SEP']);

        return $useTemplate
            ? self::applyTemplate($result, $format['FORMAT_STRING'])
            : $result;
    }

    public static function applyTemplate($value, $template)
    {
        return preg_replace('/(^|[^&])#/', '${1}'.$value, $template);
    }

    public static function checkLanguage($language)
    {
        return Currency\CurrencyManager::checkLanguage($language);
    }

    public static function isExistCurrencyLanguage($currency, $language): bool
    {
        global $DB;
        $currency = Currency\CurrencyManager::checkCurrencyID($currency);
        $language = Currency\CurrencyManager::checkLanguage($language);
        if (false === $currency || false === $language) {
            return false;
        }
        $query = "select LID from b_catalog_currency_lang where CURRENCY = '".$DB->ForSql($currency)."' and LID = '".$DB->ForSql($language)."'";
        $searchIterator = $DB->Query($query, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        $result = $searchIterator->Fetch();
        unset($searchIterator);

        return !empty($result);
    }

    public static function getParsedCurrencyFormat(string $currency): array
    {
        $result = self::$arCurrencyFormat[$currency] ?? self::GetFormatDescription($currency);

        return $result['TEMPLATE']['PARTS'];
    }

    public static function getPriceControl(string $control, string $currency): string
    {
        if ('' === $control) {
            return '';
        }
        if (!Currency\CurrencyManager::checkCurrencyID($currency)) {
            return $control;
        }
        $format = static::getParsedCurrencyFormat($currency);
        if (empty($format)) {
            return $control;
        }
        $index = array_search('#', $format, true);
        if (false === $index) {
            return $control;
        }
        $format[$index] = $control;

        return implode('', $format);
    }

    public static function getUnFormattedValue(string $formattedValue, string $currency, string $lang = LANGUAGE_ID): string
    {
        $format = static::GetCurrencyFormat($currency, $lang);

        return static::unFormatValue($formattedValue, (string) $format['THOUSANDS_SEP'], (string) $format['DEC_POINT']);
    }

    protected static function explodeFormatTemplate(string $template): ?array
    {
        $result = preg_split('/(?<!&)(#)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($result)) {
            return null;
        }
        $resultCount = count($result);
        if ($resultCount > 1) {
            $needSlice = false;
            $offset = 0;
            $count = $resultCount;
            if ('' === $result[0]) {
                $needSlice = true;
                $offset = 1;
                --$count;
            }
            if ('' === $result[$resultCount - 1]) {
                $needSlice = true;
                --$count;
            }
            if ($needSlice) {
                $result = array_slice($result, $offset, $count);
            }
            unset($count, $offset, $needSlice);
        }
        unset($resultCount);

        return $result;
    }

    protected static function clearFields($value)
    {
        return null !== $value;
    }

    protected static function unFormatValue(string $formattedValue, string $thousandsSeparator, string $decPoint): string
    {
        $result = $formattedValue;

        if ('' !== $thousandsSeparator) {
            $result = str_replace($thousandsSeparator, '', $result);
        }

        if ('.' !== $decPoint && '' !== $decPoint) {
            $result = str_replace($decPoint, '.', $result);
        }

        return $result;
    }
}
