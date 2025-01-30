<?php

// SQL Helper
class sql_helper
{
    public static function GetCount($tableName, $tableAlias, &$arFields, &$arFilter)
    {
        $tableName = (string) $tableName;
        if ('' === $tableName) {
            return false;
        }

        global $DB;
        $isOracle = 'ORACLE' === strtoupper($DB->type);

        $sql = $isOracle
            ? "SELECT COUNT(1) AS QTY FROM {$tableName}"
            : "SELECT COUNT(*) AS QTY FROM {$tableName}";

        if (is_array($arFilter) && !empty($arFilter)) {
            if (!is_array($arFields)) {
                return false;
            }

            $arJoins = [];
            $condition = self::PrepareWhere($arFields, $arFilter, $arJoins);
            if ('' !== $condition) {
                $tableAlias = (string) $tableAlias;
                if ('' !== $tableAlias) {
                    // ORA-00933 overwise
                    $sql .= $isOracle ? " {$tableAlias}" : " AS {$tableAlias}";
                }

                $sql .= " WHERE {$condition}";
            }
        }

        $dbResult = $DB->Query($sql, false, 'File: '.__FILE__.'<br/>Line: '.__LINE__);
        $arResult = $dbResult ? $dbResult->Fetch() : null;

        return null !== $arResult && isset($arResult['QTY']) ? (int) ($arResult['QTY']) : 0;
    }

    public static function GetFilterOperation($key)
    {
        $strNegative = 'N';
        if ('!' === substr($key, 0, 1)) {
            $key = substr($key, 1);
            $strNegative = 'Y';
        }

        $strOrNull = 'N';
        if ('+' === substr($key, 0, 1)) {
            $key = substr($key, 1);
            $strOrNull = 'Y';
        }

        if ('>=' === substr($key, 0, 2)) {
            $key = substr($key, 2);
            $strOperation = '>=';
        } elseif ('>' === substr($key, 0, 1)) {
            $key = substr($key, 1);
            $strOperation = '>';
        } elseif ('<=' === substr($key, 0, 2)) {
            $key = substr($key, 2);
            $strOperation = '<=';
        } elseif ('<' === substr($key, 0, 1)) {
            $key = substr($key, 1);
            $strOperation = '<';
        } elseif ('@' === substr($key, 0, 1)) {
            $key = substr($key, 1);
            $strOperation = 'IN';
        } elseif ('%' === substr($key, 0, 1)) {
            $key = substr($key, 1);
            $strOperation = 'LIKE';
        } elseif ('?' === substr($key, 0, 1)) {
            $key = substr($key, 1);
            $strOperation = 'QUERY';
        } elseif ('=' === substr($key, 0, 1)) {
            $key = substr($key, 1);
            $strOperation = '=';
        } else {
            $strOperation = '=';
        }

        return ['FIELD' => $key, 'NEGATIVE' => $strNegative, 'OPERATION' => $strOperation, 'OR_NULL' => $strOrNull];
    }

    public static function PrepareSql(&$arFields, $arOrder, $arFilter, $arGroupBy, $arSelectFields, $arOptions = [])
    {
        global $DB;

        $strSqlSelect = '';
        $strSqlFrom = '';
        $strSqlGroupBy = '';

        $arGroupByFunct = ['COUNT', 'AVG', 'MIN', 'MAX', 'SUM'];

        $arAlreadyJoined = [];

        // GROUP BY -->
        if (is_array($arGroupBy) && count($arGroupBy) > 0) {
            $arSelectFields = $arGroupBy;
            foreach ($arGroupBy as $key => $val) {
                $val = strtoupper($val);
                $key = strtoupper($key);
                if (array_key_exists($val, $arFields) && !in_array($key, $arGroupByFunct, true)) {
                    if ('' !== $strSqlGroupBy) {
                        $strSqlGroupBy .= ', ';
                    }
                    $strSqlGroupBy .= $arFields[$val]['FIELD'];

                    if (isset($arFields[$val]['FROM'])
                        && '' !== $arFields[$val]['FROM']
                        && !in_array($arFields[$val]['FROM'], $arAlreadyJoined, true)) {
                        if ('' !== $strSqlFrom) {
                            $strSqlFrom .= ' ';
                        }
                        $strSqlFrom .= $arFields[$val]['FROM'];
                        $arAlreadyJoined[] = $arFields[$val]['FROM'];
                    }
                }
            }
        }
        // <-- GROUP BY

        // SELECT -->
        $arFieldsKeys = array_keys($arFields);

        if (is_array($arGroupBy) && 0 === count($arGroupBy)) {
            $strSqlSelect = 'COUNT(%%_DISTINCT_%% '.$arFields[$arFieldsKeys[0]]['FIELD'].') as CNT ';
        } else {
            if (isset($arSelectFields) && !is_array($arSelectFields) && is_string($arSelectFields) && '' !== $arSelectFields && array_key_exists($arSelectFields, $arFields)) {
                $arSelectFields = [$arSelectFields];
            }

            if (!isset($arSelectFields)
                || !is_array($arSelectFields)
                || count($arSelectFields) <= 0) {
                self::PrepareDefaultFields($arFields, $arOrder, $arAlreadyJoined, $strSqlSelect, $strSqlFrom);
            } else {
                foreach ($arSelectFields as $key => $val) {
                    if ('*' === $val) {
                        self::PrepareDefaultFields($arFields, $arOrder, $arAlreadyJoined, $strSqlSelect, $strSqlFrom);
                    }

                    $val = strtoupper($val);
                    $key = strtoupper($key);

                    if (!array_key_exists($val, $arFields)) {
                        continue;
                    }

                    if (in_array($key, $arGroupByFunct, true)) {
                        if ('' !== $strSqlSelect) {
                            $strSqlSelect .= ', ';
                        }

                        $strSqlSelect .= $key.'('.$arFields[$val]['FIELD'].') as '.$val;
                    } else {
                        self::AddToSelect($val, $arFields[$val], $arOrder, $strSqlSelect);
                    }
                    self::AddToFrom($arFields[$val], $arAlreadyJoined, $strSqlFrom);
                }
            }

            if ('' !== $strSqlGroupBy) {
                if ('' !== $strSqlSelect) {
                    $strSqlSelect .= ', ';
                }
                $strSqlSelect .= 'COUNT(%%_DISTINCT_%% '.$arFields[$arFieldsKeys[0]]['FIELD'].') as CNT';
            } else {
                $strSqlSelect = '%%_DISTINCT_%% '.$strSqlSelect;
            }
        }
        // <-- SELECT

        // WHERE -->
        $arJoins = [];
        $strSqlWhere = self::PrepareWhere($arFields, $arFilter, $arJoins);

        foreach ($arJoins as $join) {
            if ('' !== $join && !in_array($join, $arAlreadyJoined, true)) {
                if ('' !== $strSqlFrom) {
                    $strSqlFrom .= ' ';
                }

                $strSqlFrom .= $join;
                $arAlreadyJoined[] = $join;
            }
        }
        // <-- WHERE

        // ORDER BY -->
        $arSqlOrder = [];
        $dbType = strtoupper($DB->type);
        $nullsLast = is_array($arOptions) && isset($arOptions['NULLS_LAST']) ? (bool) $arOptions['NULLS_LAST'] : false;
        foreach ($arOrder as $by => $order) {
            $by = strtoupper($by);
            $order = strtoupper($order);

            if ('ASC' !== $order) {
                $order = 'DESC';
            }

            if (array_key_exists($by, $arFields)) {
                if (!$nullsLast) {
                    if ('ORACLE' !== $dbType) {
                        $arSqlOrder[] = $arFields[$by]['FIELD'].' '.$order;
                    } else {
                        if ('ASC' === $order) {
                            $arSqlOrder[] = $arFields[$by]['FIELD'].' '.$order.' NULLS FIRST';
                        } else {
                            $arSqlOrder[] = $arFields[$by]['FIELD'].' '.$order.' NULLS LAST';
                        }
                    }
                } else {
                    if ('MYSQL' === $dbType) {
                        if ('ASC' === $order) {
                            $arSqlOrder[] = '(CASE WHEN ISNULL('.$arFields[$by]['FIELD'].') THEN 1 ELSE 0 END) '.$order.', '.$arFields[$by]['FIELD'].' '.$order;
                        } else {
                            $arSqlOrder[] = $arFields[$by]['FIELD'].' '.$order;
                        }
                    } elseif ('MSSQL' === $dbType) {
                        if ('ASC' === $order) {
                            $arSqlOrder[] = '(CASE WHEN '.$arFields[$by]['FIELD'].' IS NULL THEN 1 ELSE 0 END) '.$order.', '.$arFields[$by]['FIELD'].' '.$order;
                        } else {
                            $arSqlOrder[] = $arFields[$by]['FIELD'].' '.$order;
                        }
                    } elseif ('ORACLE' === $dbType) {
                        if ('DESC' === $order) {
                            $arSqlOrder[] = $arFields[$by]['FIELD'].' '.$order.' NULLS LAST';
                        } else {
                            $arSqlOrder[] = $arFields[$by]['FIELD'].' '.$order;
                        }
                    }
                }

                if (isset($arFields[$by]['FROM'])
                    && '' !== $arFields[$by]['FROM']
                    && !in_array($arFields[$by]['FROM'], $arAlreadyJoined, true)) {
                    if ('' !== $strSqlFrom) {
                        $strSqlFrom .= ' ';
                    }
                    $strSqlFrom .= $arFields[$by]['FROM'];
                    $arAlreadyJoined[] = $arFields[$by]['FROM'];
                }
            }
        }

        $strSqlOrderBy = '';
        DelDuplicateSort($arSqlOrder);
        $sqlOrderQty = count($arSqlOrder);
        for ($i = 0; $i < $sqlOrderQty; ++$i) {
            if ('' !== $strSqlOrderBy) {
                $strSqlOrderBy .= ', ';
            }

            $strSqlOrderBy .= $arSqlOrder[$i];
        }
        // <-- ORDER BY

        return [
            'SELECT' => $strSqlSelect,
            'FROM' => $strSqlFrom,
            'WHERE' => $strSqlWhere,
            'GROUPBY' => $strSqlGroupBy,
            'ORDERBY' => $strSqlOrderBy,
        ];
    }

    public static function GetRowCount(&$arSql, $tableName, $tableAlias = '', $dbType = '')
    {
        global $DB;

        $tableName = (string) $tableName;
        $tableAlias = (string) $tableAlias;
        $dbType = (string) $dbType;
        if (!isset($dbType[0])) {
            $dbType = 'MYSQL';
        }

        $dbType = strtoupper($dbType);

        $query = 'SELECT COUNT(\'x\') as CNT FROM '.$tableName;

        if ('' !== $tableAlias) {
            $query .= ' '.$tableAlias;
        }

        if (isset($arSql['FROM'][0])) {
            $query .= ' '.$arSql['FROM'];
        }

        if (isset($arSql['WHERE'][0])) {
            $query .= ' WHERE '.$arSql['WHERE'];
        }

        if (isset($arSql['GROUPBY'][0])) {
            $query .= ' GROUP BY '.$arSql['GROUPBY'];
        }

        $rs = $DB->Query($query, false, 'File: '.__FILE__.'<br/>Line: '.__LINE__);
        // MYSQL, MSSQL, ORACLE
        $result = 0;
        while ($ary = $rs->Fetch()) {
            $result += (int) $ary['CNT'];
        }

        return $result;
    }

    public static function PrepareSelectTop(&$sql, $top, $dbType)
    {
        $dbType = (string) $dbType;
        if (!isset($dbType[0])) {
            $dbType = 'MYSQL';
        }

        $dbType = strtoupper($dbType);

        if ('MYSQL' === $dbType) {
            $sql .= ' LIMIT '.$top;
        } elseif ('MSSQL' === $dbType) {
            if ('SELECT ' === substr($sql, 0, 7)) {
                $sql = 'SELECT TOP '.$top.substr($sql, 6);
            }
        } elseif ('ORACLE' === $dbType) {
            $sql = 'SELECT * FROM ('.$sql.') WHERE ROWNUM <= '.$top;
        }
    }

    private static function AddToSelect(&$fieldKey, &$arField, &$arOrder, &$strSqlSelect)
    {
        global $DB;

        if ('' !== $strSqlSelect) {
            $strSqlSelect .= ', ';
        }

        // ORACLE AND MSSQL require datetime/date field in select list if it present in order list
        if ('datetime' === $arField['TYPE']) {
            if (('ORACLE' === strtoupper($DB->type) || 'MSSQL' === strtoupper($DB->type)) && array_key_exists($fieldKey, $arOrder)) {
                $strSqlSelect .= $arField['FIELD'].' as '.$fieldKey.'_X1, ';
            }

            $strSqlSelect .= $DB->DateToCharFunction($arField['FIELD'], 'FULL').' as '.$fieldKey;
        } elseif ('date' === $arField['TYPE']) {
            if (('ORACLE' === strtoupper($DB->type) || 'MSSQL' === strtoupper($DB->type)) && array_key_exists($fieldKey, $arOrder)) {
                $strSqlSelect .= $arField['FIELD'].' as '.$fieldKey.'_X1, ';
            }

            $strSqlSelect .= $DB->DateToCharFunction($arField['FIELD'], 'SHORT').' as '.$fieldKey;
        } else {
            $strSqlSelect .= $arField['FIELD'].' as '.$fieldKey;
        }
    }

    private static function AddToFrom(&$arField, &$arJoined, &$strSqlFrom)
    {
        if (isset($arField['FROM'])
            && '' !== $arField['FROM']
            && !in_array($arField['FROM'], $arJoined, true)) {
            if ('' !== $strSqlFrom) {
                $strSqlFrom .= ' ';
            }
            $strSqlFrom .= $arField['FROM'];
            $arJoined[] = $arField['FROM'];
        }
    }

    private static function PrepareDefaultFields(&$arFields, &$arOrder, &$arJoined, &$strSqlSelect, &$strSqlFrom)
    {
        $arFieldsKeys = array_keys($arFields);
        $qty = count($arFieldsKeys);
        for ($i = 0; $i < $qty; ++$i) {
            if (isset($arFields[$arFieldsKeys[$i]]['WHERE_ONLY'])
                && 'Y' === $arFields[$arFieldsKeys[$i]]['WHERE_ONLY']) {
                continue;
            }

            if (isset($arFields[$arFieldsKeys[$i]]['DEFAULT'])
                && 'N' === $arFields[$arFieldsKeys[$i]]['DEFAULT']) {
                continue;
            }

            self::AddToSelect($arFieldsKeys[$i], $arFields[$arFieldsKeys[$i]], $arOrder, $strSqlSelect);
            self::AddToFrom($arFields[$arFieldsKeys[$i]], $arJoined, $strSqlFrom);
        }
    }

    private static function PrepareWhere(&$arFields, &$arFilter, &$arJoins)
    {
        global $DB;
        $arSqlSearch = [];

        if (!is_array($arFilter)) {
            $filter_keys = [];
        } else {
            $filter_keys = array_keys($arFilter);
        }

        $keyQty = count($filter_keys);
        for ($i = 0; $i < $keyQty; ++$i) {
            $vals = $arFilter[$filter_keys[$i]];
            if (!is_array($vals)) {
                $vals = [$vals];
            }

            $key = $filter_keys[$i];

            if (str_starts_with($key, '__INNER_FILTER')) {
                $arSqlSearch[] = '('.self::PrepareWhere($arFields, $vals, $arJoins).')';

                continue;
            }

            $key_res = self::GetFilterOperation($key);
            $key = $key_res['FIELD'];
            $strNegative = $key_res['NEGATIVE'];
            $strOperation = $key_res['OPERATION'];
            $strOrNull = $key_res['OR_NULL'];

            if (array_key_exists($key, $arFields)) {
                $arSqlSearch_tmp = [];

                if (count($vals) > 0) {
                    if ('IN' === $strOperation) {
                        if (isset($arFields[$key]['WHERE'])) {
                            $arSqlSearch_tmp1 = call_user_func_array(
                                $arFields[$key]['WHERE'],
                                [$vals, $key, $strOperation, $strNegative, $arFields[$key]['FIELD'], &$arFields, &$arFilter]
                            );
                            if (false !== $arSqlSearch_tmp1) {
                                $arSqlSearch_tmp[] = $arSqlSearch_tmp1;
                            }
                        } else {
                            if ('int' === $arFields[$key]['TYPE']) {
                                array_walk($vals, create_function('&$item', '$item=IntVal($item);'));
                                $vals = array_unique($vals);
                                $val = implode(',', $vals);

                                if (count($vals) <= 0) {
                                    $arSqlSearch_tmp[] = '(1 = 2)';
                                } else {
                                    $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' NOT ' : '').'('.$arFields[$key]['FIELD'].' IN ('.$val.'))';
                                }
                            } elseif ('double' === $arFields[$key]['TYPE']) {
                                array_walk($vals, create_function('&$item', '$item=DoubleVal($item);'));
                                $vals = array_unique($vals);
                                $val = implode(',', $vals);

                                if (count($vals) <= 0) {
                                    $arSqlSearch_tmp[] = '(1 = 2)';
                                } else {
                                    $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' ('.$val.'))';
                                }
                            } elseif ('string' === $arFields[$key]['TYPE'] || 'char' === $arFields[$key]['TYPE']) {
                                array_walk($vals, create_function('&$item', "\$item=\"'\".\$GLOBALS[\"DB\"]->ForSql(\$item).\"'\";"));
                                $vals = array_unique($vals);
                                $val = implode(',', $vals);

                                if (count($vals) <= 0) {
                                    $arSqlSearch_tmp[] = '(1 = 2)';
                                } else {
                                    $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' ('.$val.'))';
                                }
                            } elseif ('datetime' === $arFields[$key]['TYPE']) {
                                array_walk($vals, create_function('&$item', "\$item=\"'\".\$GLOBALS[\"DB\"]->CharToDateFunction(\$GLOBALS[\"DB\"]->ForSql(\$item), \"FULL\").\"'\";"));
                                $vals = array_unique($vals);
                                $val = implode(',', $vals);

                                if (count($vals) <= 0) {
                                    $arSqlSearch_tmp[] = '1 = 2';
                                } else {
                                    $arSqlSearch_tmp[] = ('Y' === $strNegative ? ' NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' ('.$val.'))';
                                }
                            } elseif ('date' === $arFields[$key]['TYPE']) {
                                array_walk($vals, create_function('&$item', "\$item=\"'\".\$GLOBALS[\"DB\"]->CharToDateFunction(\$GLOBALS[\"DB\"]->ForSql(\$item), \"SHORT\").\"'\";"));
                                $vals = array_unique($vals);
                                $val = implode(',', $vals);

                                if (count($vals) <= 0) {
                                    $arSqlSearch_tmp[] = '1 = 2';
                                } else {
                                    $arSqlSearch_tmp[] = ('Y' === $strNegative ? ' NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' ('.$val.'))';
                                }
                            }
                        }
                    } else {
                        $valQty = count($vals);
                        for ($j = 0; $j < $valQty; ++$j) {
                            $val = $vals[$j];

                            if (isset($arFields[$key]['WHERE'])) {
                                $arSqlSearch_tmp1 = call_user_func_array(
                                    $arFields[$key]['WHERE'],
                                    [$val, $key, $strOperation, $strNegative, $arFields[$key]['FIELD'], &$arFields, &$arFilter]
                                );
                                if (false !== $arSqlSearch_tmp1) {
                                    $arSqlSearch_tmp[] = $arSqlSearch_tmp1;
                                }
                            } else {
                                $fieldType = $arFields[$key]['TYPE'];
                                $fieldName = $arFields[$key]['FIELD'];
                                if ('QUERY' === $strOperation && 'string' !== $fieldType && 'char' !== $fieldType) {
                                    // Ignore QUERY operation for not character types - QUERY is supported only for character types.
                                    $strOperation = '=';
                                }

                                if ('LIKE' === $strOperation && ('int' === $fieldType || 'double' === $fieldType)) {
                                    // Ignore LIKE operation for numeric types.
                                    $strOperation = '=';
                                }

                                if ('int' === $fieldType) {
                                    if ((0 === (int) $val) && str_contains($strOperation, '=')) {
                                        $arSqlSearch_tmp[] = '('.$arFields[$key]['FIELD'].' IS '.(('Y' === $strNegative) ? 'NOT ' : '').'NULL) '.(('Y' === $strNegative) ? 'AND' : 'OR').' '.(('Y' === $strNegative) ? 'NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' 0)';
                                    } else {
                                        $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.(int) $val.' )';
                                    }
                                } elseif ('double' === $fieldType) {
                                    $val = str_replace(',', '.', $val);

                                    if ((0 === (float) $val) && str_contains($strOperation, '=')) {
                                        $arSqlSearch_tmp[] = '('.$arFields[$key]['FIELD'].' IS '.(('Y' === $strNegative) ? 'NOT ' : '').'NULL) '.(('Y' === $strNegative) ? 'AND' : 'OR').' '.(('Y' === $strNegative) ? 'NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' 0)';
                                    } else {
                                        $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.(float) $val.' )';
                                    }
                                } elseif ('string' === $fieldType || 'char' === $fieldType) {
                                    if ('QUERY' === $strOperation) {
                                        $arSqlSearch_tmp[] = GetFilterQuery($fieldName, $val, 'Y');
                                    } else {
                                        if (('' === $val) && str_contains($strOperation, '=')) {
                                            $arSqlSearch_tmp[] = '('.$fieldName.' IS '.(('Y' === $strNegative) ? 'NOT ' : '').'NULL) '.(('Y' === $strNegative) ? 'AND NOT' : 'OR').' ('.$DB->Length($fieldName).' <= 0) '.(('Y' === $strNegative) ? 'AND NOT' : 'OR').' ('.$fieldName.' '.$strOperation." '".$DB->ForSql($val)."' )";
                                        } else {
                                            if ('LIKE' === $strOperation) {
                                                if (is_array($val)) {
                                                    $arSqlSearch_tmp[] = '('.$fieldName." LIKE '%".implode("%' ESCAPE '!' OR ".$fieldName." LIKE '%", self::ForLike($val))."%' ESCAPE '!')";
                                                } elseif ('' === $val) {
                                                    $arSqlSearch_tmp[] = $fieldName;
                                                } else {
                                                    $arSqlSearch_tmp[] = $fieldName." LIKE '%".self::ForLike($val)."%' ESCAPE '!'";
                                                }
                                            } else {
                                                $arSqlSearch_tmp[] = (('Y' === $strNegative) ? ' '.$fieldName.' IS NULL OR NOT ' : '').'('.$fieldName.' '.$strOperation." '".$DB->ForSql($val)."' )";
                                            }
                                        }
                                    }
                                } elseif ('datetime' === $fieldType) {
                                    if ('' === $val) {
                                        $arSqlSearch_tmp[] = ('Y' === $strNegative ? 'NOT' : '').'('.$arFields[$key]['FIELD'].' IS NULL)';
                                    } else {
                                        $arSqlSearch_tmp[] = ('Y' === $strNegative ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.$DB->CharToDateFunction($DB->ForSql($val), 'FULL').')';
                                    }
                                } elseif ('date' === $fieldType) {
                                    if ('' === $val) {
                                        $arSqlSearch_tmp[] = ('Y' === $strNegative ? 'NOT' : '').'('.$arFields[$key]['FIELD'].' IS NULL)';
                                    } else {
                                        $arSqlSearch_tmp[] = ('Y' === $strNegative ? ' '.$arFields[$key]['FIELD'].' IS NULL OR NOT ' : '').'('.$arFields[$key]['FIELD'].' '.$strOperation.' '.$DB->CharToDateFunction($DB->ForSql($val), 'SHORT').')';
                                    }
                                }
                            }
                        }
                    }
                }

                if (isset($arFields[$key]['FROM'])
                    && '' !== $arFields[$key]['FROM']
                    && !in_array($arFields[$key]['FROM'], $arJoins, true)) {
                    $arJoins[] = $arFields[$key]['FROM'];
                }

                $strSqlSearch_tmp = '';
                $sqlSearchQty = count($arSqlSearch_tmp);
                for ($j = 0; $j < $sqlSearchQty; ++$j) {
                    if ($j > 0) {
                        $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                    }
                    $strSqlSearch_tmp .= self::AddBrackets($arSqlSearch_tmp[$j]);
                }
                if ('Y' === $strOrNull) {
                    if ('' !== $strSqlSearch_tmp) {
                        $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                    }
                    $strSqlSearch_tmp .= '('.$arFields[$key]['FIELD'].' IS '.('Y' === $strNegative ? 'NOT ' : '').'NULL)';

                    if ('int' === $arFields[$key]['TYPE'] || 'double' === $arFields[$key]['TYPE']) {
                        if ('' !== $strSqlSearch_tmp) {
                            $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                        }
                        $strSqlSearch_tmp .= '('.$arFields[$key]['FIELD'].' '.('Y' === $strNegative ? '<>' : '=').' 0)';
                    } elseif ('string' === $arFields[$key]['TYPE'] || 'char' === $arFields[$key]['TYPE']) {
                        if ('' !== $strSqlSearch_tmp) {
                            $strSqlSearch_tmp .= ('Y' === $strNegative ? ' AND ' : ' OR ');
                        }
                        $strSqlSearch_tmp .= '('.$arFields[$key]['FIELD'].' '.('Y' === $strNegative ? '<>' : '=')." '')";
                    }
                }

                if ('' !== $strSqlSearch_tmp) {
                    $arSqlSearch[] = $strSqlSearch_tmp;
                }
            }
        }

        $logic = 'AND';
        if (isset($arFilter['LOGIC']) && '' !== $arFilter['LOGIC']) {
            $logic = strtoupper($arFilter['LOGIC']);
            if ('AND' !== $logic && 'OR' !== $logic) {
                $logic = 'AND';
            }
        }

        $strSqlWhere = '';
        $logic = " {$logic} ";
        $sqlSearchQty = count($arSqlSearch);
        for ($i = 0; $i < $sqlSearchQty; ++$i) {
            $searchItem = $arSqlSearch[$i];

            if ('' === $searchItem) {
                continue;
            }

            if ('' !== $strSqlWhere) {
                $strSqlWhere .= $logic;
            }

            $strSqlWhere .= "({$searchItem})";
        }

        return $strSqlWhere;
    }

    private static function AddBrackets($str)
    {
        return preg_match('/^\(.*\)$/s', $str) > 0 ? $str : "({$str})";
    }

    private static function ForLike($str)
    {
        global $DB;
        static $search = ['!', '_', '%'];
        static $replace = ['!!', '!_', '!%'];

        return str_replace($search, $replace, $DB->ForSQL($str));
    }
}
