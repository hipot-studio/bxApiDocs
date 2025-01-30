<?php

// <title>CommerceML</title>
IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/import_setup_templ.php');
$startImportExecTime = getmicrotime();

global $USER;
$bTmpUserCreated = false;
if (!CCatalog::IsUserExists()) {
    $bTmpUserCreated = true;
    if (isset($USER)) {
        $USER_TMP = $USER;
        unset($USER);
    }

    $USER = new CUser();
}

$strImportErrorMessage = '';
$strImportOKMessage = '';

if (file_exists($_SERVER['DOCUMENT_ROOT'].'/bitrix/php_interface/include/1c_mutator.php')) {
    include $_SERVER['DOCUMENT_ROOT'].'/bitrix/php_interface/include/1c_mutator.php';
}

ClearVars('f_');

class XMLNode
{
    public $childs;
    public $parent;
    public $value;
    public $attributes;
    public $name;
    public $r;

    public function __construct(&$parent, $attributes, $name)
    {
        $this->r = rand();
        $this->parent = $parent;
        $this->attributes = $attributes;
        $this->name = $name;
        $this->childs = null;
        $this->value = rand();
        if (null !== $parent) {
            $this->parent->childs[$name][] = $this;
        }
    }

    public function GetAttribute($name)
    {
        return isset($this->attributes[$name]) ? $this->attributes[$name] : null;
    }

    public function XMLSetValue($new_val)
    {
        $this->r = rand();
        //		echo " SetValue===".$this->name." [".$this->r."] = ".$new_val." \n";
        $this->value = $new_val;
    }

    public function &select_nodes($str_node)
    {
        $tmp = trim($str_node, "/ \r\n\t\0\x0B");
        if (!($p = strpos($tmp, '/'))) {
            $p = strlen($tmp);
        }
        $str_node = trim(substr($tmp, 0, $p));
        if ('' === $str_node) {
            return $this;
        }

        $tmp = trim(substr($tmp, $p + 1));
        if ('' === $tmp) {
            return $this->childs[$str_node];
        }

        return $this->childs[$str_node][0]->select_nodes($tmp);
        if (!is_array($this->childs)) {
            return [];
        }
        $result = [0 => $this->childs];

        for ($i = 1, $intCount = count($tmp); $i < $intCount; ++$i) {
            if ('' !== $tmp[$i]) {
                if (!is_array($result[0]->childs)) {
                    return [];
                }
                $result = $result[0]->childs[$tmp[$i]];
            }
        }

        return $result;
    }
}

class XMLParser
{
    public $parser;
    public $cur;
    public $xml;

    public function Load($file)
    {
        if ($fd = fopen($file, 'r')) {
            $content = fread($fd, filesize($file));
            fclose($fd);
            $this->parse($content);

            return true;
        }

        return false;
    }

    public function LoadString(&$text)
    {
        if ('' !== $text) {
            $this->parse($text);

            return true;
        }

        return false;
    }

    public static function unquote($str)
    {
        $search = [
            "'&(quot|#34);'i",
            "'&(amp|#38);'i",
            "'&(lt|#60);'i",
            "'&(gt|#62);'i",
            "'&#(\\d+);'e",
        ];

        $replace = [
            '"',
            '&',
            '<',
            '>',
            'chr(\\1)',
        ];

        return preg_replace($search, $replace, $str);
    }

    public function parse(&$data)		// , $last
    {
        $data = preg_replace('#<\\!--.*?-->#s', '', $data);

        $pb = strpos($data, '<');
        while (false !== $pb) {
            $pe = strpos($data, '>', $pb);
            if (false === $pe) {
                break;
            }
            $tag_cont = substr($data, $pb + 1, $pe - $pb - 1);
            $pb = strpos($data, '<', $pe);

            $check_str = substr($tag_cont, 0, 1);
            if ('?' === $check_str) {
                continue;
            }
            if ('!' === $check_str) {
                continue;
            }
            if ('/' === $check_str) {
                $this->endElement(substr($tag_cont, 1));
            } else {
                $p = 0;
                $ltag_cont = strlen($tag_cont);
                while (($p < $ltag_cont) && (!str_contains(" \t\n\r", substr($tag_cont, $p, 1)))) {
                    ++$p;
                }
                $name = substr($tag_cont, 0, $p);
                $at = substr($tag_cont, $p);
                if (str_contains($at, '&')) {
                    $bAmp = true;
                } else {
                    $bAmp = false;
                }

                preg_match_all('/(\\S+)\\s*=\\s*["](.*?)["]/s'.BX_UTF_PCRE_MODIFIER, $at, $attrs_tmp);
                $attrs = [];
                for ($i = 0, $intCount = count($attrs_tmp[1]); $i < $intCount; ++$i) {
                    $attrs[$attrs_tmp[1][$i]] = ($bAmp ? $this->unquote($attrs_tmp[2][$i]) : $attrs_tmp[2][$i]);
                }
                $this->startElement($name, $attrs);
                if ('/' === substr($tag_cont, -1)) {
                    $this->endElement($name);
                }
            }
        }
    }

    public function startElement($name, $attrs)
    {
        $bTemp = false;
        if (null === $this->cur) {
            $bTemp = true;
        }

        $this->cur = new XMLNode($this->cur, $attrs, $name);

        if ($bTemp) {
            $this->xml = $this->cur;
        }
    }

    public function endElement($name)
    {
        if (null !== $this->cur->parent) {
            $this->cur = $this->cur->parent;
        }
    }

    public function cdata($parser, $cdata)
    {
        if ('' !== trim($cdata)) {
            $this->cur->XMLSetValue(trim($cdata));
        }
    }

    public function select_nodes_attrib($str_node)
    {
        $tmp = $this->select_nodes($str_node);
        $result = [];
        for ($i = 0, $intCount = count($tmp); $i < $intCount; ++$i) {
            $arTemp = $tmp[$i]->attributes;
            $result[] = $arTemp;
        }

        return $result;
    }

    public function select_nodes($str_node)
    {
        if (!is_object($this->xml)) {
            return [];
        }
        $result = [0 => $this->xml];
        $tmp = explode('/', $str_node);
        for ($i = 2, $intCount = count($tmp); $i < $intCount; ++$i) {
            if ('' !== $tmp[$i]) {
                if (!is_array($result[0]->childs)) {
                    return [];
                }
                $result = $result[0]->childs[$tmp[$i]];
            }
        }

        return $result;
    }
}

function AddSectionsRecursive($parent = '', $parent_id = 0)
{
    global $tmpid, $arGrTmp, $strImportErrorMessage, $IBLOCK_ID, $arGroups, $STT_GROUP_ADD, $STT_GROUP_UPDATE, $STT_GROUP_ERROR;

    global $boolTranslitSection, $arTranslitSection;

    global $activateFileData;

    $arChld = $arGrTmp[$tmpid.$parent];
    if (!is_array($arChld)) {
        return;
    }
    for ($i = 0, $intCount = count($arChld); $i < $intCount; ++$i) {
        $GROUP_XML_ID = $arChld[$i]['GROUP_XML_ID'];
        $GROUP_NAME = $arChld[$i]['GROUP_NAME'];

        $bs = new CIBlockSection();
        $res = CIBlockSection::GetList([], ['XML_ID' => $GROUP_XML_ID, 'IBLOCK_ID' => $IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N']);
        $bNewGroup_tmp = false;
        if ($arr = $res->Fetch()) {
            $GROUP_ID = $arr['ID'];
            $arFields = [
                'NAME' => $GROUP_NAME,
                'TMP_ID' => $tmpid,
                'IBLOCK_SECTION_ID' => $parent_id,
            ];
            if ($boolTranslitSection) {
                $arFields['CODE'] = CUtil::translit($GROUP_NAME, 'ru', $arTranslitSection);
            }
            if ('Y' === $activateFileData) {
                $arFields['ACTIVE'] = 'Y';
            }
            $res = $bs->Update($GROUP_ID, $arFields, false);
        } else {
            $bNewGroup_tmp = true;
            $arFields = [
                'ACTIVE' => 'Y',
                'IBLOCK_SECTION_ID' => $parent_id,
                'IBLOCK_ID' => $IBLOCK_ID,
                'NAME' => $GROUP_NAME,
                'TMP_ID' => $tmpid,
                'XML_ID' => $GROUP_XML_ID,
            ];
            if ($boolTranslitSection) {
                $arFields['CODE'] = CUtil::translit($GROUP_NAME, 'ru', $arTranslitSection);
            }
            $GROUP_ID = $bs->Add($arFields, false);
            $res = ($GROUP_ID > 0);
        }

        if (!$res) {
            $strImportErrorMessage .= str_replace('#ERROR#', $bs->LAST_ERROR, str_replace('#NAME#', '['.$GROUP_ID.'] "'.$GROUP_NAME.'" ('.$GROUP_XML_ID.')', GetMessage('CICML_ERROR_ADD_SECTION'))).'.<br>';
            ++$STT_GROUP_ERROR;
        } else {
            if ($bNewGroup_tmp) {
                ++$STT_GROUP_ADD;
            } else {
                $STT_GROUP_UPDATE++;
            }

            $arGroups[$GROUP_XML_ID] = $GROUP_ID;
            AddSectionsRecursive($GROUP_XML_ID, $GROUP_ID);
        }
    }
}

if (!function_exists('file_get_contents')) {
    function file_get_contents($filename)
    {
        $fd = fopen("{$filename}", 'r');
        $content = fread($fd, filesize($filename));
        fclose($fd);

        return $content;
    }
}

$DATA_FILE_NAME = '';

$STT_GROUP_ADD = 0;
$STT_GROUP_UPDATE = 0;
$STT_GROUP_ERROR = 0;
$STT_CATALOG_ADD = 0;
$STT_CATALOG_UPDATE = 0;
$STT_CATALOG_ERROR = 0;
$STT_PROP_ADD = 0;
$STT_PROP_UPDATE = 0;
$STT_PROP_ERROR = 0;
$STT_PRODUCT_ADD = 0;
$STT_PRODUCT_UPDATE = 0;
$STT_PRODUCT_ERROR = 0;

if (isset($_FILES['FILE_1C']) && is_uploaded_file($_FILES['FILE_1C']['tmp_name'])) {
    $DATA_FILE_NAME = $_FILES['FILE_1C']['tmp_name'];
}

if ('' === $DATA_FILE_NAME) {
    if ('' !== $URL_FILE_1C) {
        $URL_FILE_1C = Rel2Abs('/', $URL_FILE_1C);
        if (file_exists($_SERVER['DOCUMENT_ROOT'].$URL_FILE_1C) && is_file($_SERVER['DOCUMENT_ROOT'].$URL_FILE_1C)) {
            $DATA_FILE_NAME = $_SERVER['DOCUMENT_ROOT'].$URL_FILE_1C;
        }
    }
}

if ('' === $DATA_FILE_NAME) {
    $strImportErrorMessage .= GetMessage('CICML_NO_LOAD_FILE').'<br>';
}

global $IBLOCK_TYPE_ID;
$IBLOCK_TYPE_ID = trim((string) $IBLOCK_TYPE_ID);
if ('' !== $IBLOCK_TYPE_ID) {
    $rsIBlockTypes = CIBlockType::GetByID($IBLOCK_TYPE_ID);
    if (!($arIBlockType = $rsIBlockTypes->Fetch())) {
        $IBLOCK_TYPE_ID = '';
    }
}
if ('' === $IBLOCK_TYPE_ID) {
    $IBLOCK_TYPE_ID = COption::GetOptionString('catalog', 'default_catalog_1c', '');
}
if ('' === $IBLOCK_TYPE_ID) {
    $iblocks = CIBlockType::GetList(['SORT' => 'ASC']);
    if ($iblocks->ExtractFields('f_')) {
        $IBLOCK_TYPE_ID = $f_ID;
    }
}

if ('' === $IBLOCK_TYPE_ID) {
    $strImportErrorMessage .= GetMessage('CICML_NO_IBLOCK').'<br>';
}

if ('F' !== $outFileAction && 'H' !== $outFileAction && 'D' !== $outFileAction) {
    $outFileAction = COption::GetOptionString('catalog', 'default_outfile_action', 'D');
}

if ('F' !== $outFileAction && 'H' !== $outFileAction) {
    $outFileAction = 'D';
}

if ('' === $strImportErrorMessage) {
    $xml = new XMLParser();
    $xml_content = file_get_contents($DATA_FILE_NAME);

    if (!$xml_content || '' === $xml_content) {
        $strImportErrorMessage .= GetMessage('CICML_NO_LOAD_DATA').'<br>';
    }
}

if ('' === $strImportErrorMessage) {
    if ('Y' !== $CONVERT_UTF8 && 'N' !== $CONVERT_UTF8) {
        $CONVERT_UTF8 = COption::GetOptionString('catalog', 'default_convert_utf8', 'N');
    }

    if (true) {
        $xml_content = $GLOBALS['APPLICATION']->ConvertCharset($xml_content, 'Y' === $CONVERT_UTF8 ? 'utf-8' : 'windows-1251', LANG_CHARSET);

        if (!$xml_content || '' === $xml_content) {
            if ($ex = $APPLICATION->GetException()) {
                $strImportErrorMessage .= $ex->GetString();
            } else {
                $strImportErrorMessage .= 'Error converting from UTF-8'.'<br>';
            }
        }
    }
}

$USE_TRANSLIT = (isset($USE_TRANSLIT) && 'Y' === $USE_TRANSLIT ? 'Y' : 'N');
$ADD_TRANSLIT = (isset($ADD_TRANSLIT) && 'Y' === $ADD_TRANSLIT ? 'Y' : 'N');

if (!isset($keepExistingProperties)) {
    $keepExistingProperties = ($outFileAction = 'D' ? 'N' : 'Y');
} else {
    $keepExistingProperties = ('N' === $keepExistingProperties ? 'N' : 'Y');
}

$activateFileData = (isset($activateFileData) && 'Y' === $activateFileData ? 'Y' : 'N');

global $nameUTF;
$nameUTF = [];

include __DIR__.'/ru/commerceml_run_name.php';
if (!isset($nameUTF) || !is_array($nameUTF) || empty($nameUTF)) {
    $strImportErrorMessage .= GetMessage('CAT_ADM_CML1_IMP_ERR_NAMEUTF').'<br>';
}

if ('' === $strImportErrorMessage) {
    $xml->LoadString($xml_content);

    $comm = $xml->select_nodes('/BizTalk/Body/'.$nameUTF['CommerceInfo']);
    if (!is_object($comm[0])) {
        $comm = $xml->select_nodes('/'.$nameUTF['CommerceInfo']);
        if (!is_object($comm[0])) {
            $strImportErrorMessage .= GetMessage('CICML_INVALID_FILE').'<br>';
        }
    }
    $strVersion = $comm[0]->GetAttribute($nameUTF['CommerceInfoVersion']);
    if (null !== $strVersion) {
        if (version_compare($strVersion, '2.0') >= 0) {
            $strImportErrorMessage .= GetMessage('CICML_INVALID_VERSION').'<br>';
        }
    }
}

if ('' === $strImportErrorMessage) {
    $arPriceType = [];
    $offerlists = $comm[0]->select_nodes('/'.$nameUTF['OffersList']);
    for ($i = 0, $intOFCount = count($offerlists); $i < $intOFCount; ++$i) {
        $xOfferListNode = $offerlists[$i];

        $props = $xOfferListNode->select_nodes('/'.$nameUTF['PropertyValue']);
        for ($j = 0, $intPropCount = count($props); $j < $intPropCount; ++$j) {
            $arPriceType[$props[0]->GetAttribute($nameUTF['CatalogID'])] = $props[0]->GetAttribute($nameUTF['PropertyId']);

            break;
        }
    }

    $SITE_ID = 'ru';
    $dbSite = CSite::GetByID($SITE_ID);
    if (!$dbSite->Fetch()) {
        $dbSite = CSite::GetList();
        $arSite = $dbSite->Fetch();
        $SITE_ID = $arSite['ID'];
    }

    $tmpid = md5(uniqid(''));
    $arCatalogs = [];
    $arCatalogsParams = [];
    $catalogs = $comm[0]->select_nodes('/'.$nameUTF['Catalog']);
    $ib = new CIBlock();
    for ($i = 0, $intCatCount = count($catalogs); $i < $intCatCount; ++$i) {
        $xCatNode = $catalogs[$i];

        $IBLOCK_XML_ID = $xCatNode->GetAttribute($nameUTF['ID']);
        $IBLOCK_NAME = $xCatNode->GetAttribute($nameUTF['Name']);
        $IBLOCK_DESC = $xCatNode->GetAttribute($nameUTF['Description']);

        $res = CIBlock::GetList([], ['=TYPE' => $IBLOCK_TYPE_ID, '=XML_ID' => $IBLOCK_XML_ID, 'CHECK_PERMISSIONS' => 'Y', 'MIN_PERMISSION' => 'W']);
        $bNewRecord_tmp = false;
        if ($res_arr = $res->Fetch()) {
            $IBLOCK_ID = $res_arr['ID'];
            $res = $ib->Update(
                $IBLOCK_ID,
                [
                    'NAME' => $IBLOCK_NAME,
                    'TMP_ID' => $tmpid,
                    'DESCRIPTION' => $IBLOCK_DESC,
                ]
            );
        } elseif ($USER->IsAdmin()) {
            $bNewRecord_tmp = true;
            $arFields = [
                'ACTIVE' => 'Y',
                'NAME' => $IBLOCK_NAME,
                'XML_ID' => $IBLOCK_XML_ID,
                'TMP_ID' => $tmpid,
                'IBLOCK_TYPE_ID' => $IBLOCK_TYPE_ID,
                'LID' => $SITE_ID,
                'WORKFLOW' => 'N',
            ];
            if ('Y' === $USE_TRANSLIT && 'Y' === $ADD_TRANSLIT) {
                $arFields['FIELDS'] = [
                    'CODE' => [
                        'DEFAULT_VALUE' => [
                            'TRANSLITERATION' => 'Y',
                        ],
                    ],
                    'SECTION_CODE' => [
                        'DEFAULT_VALUE' => [
                            'TRANSLITERATION' => 'Y',
                        ],
                    ],
                ];
            }

            $IBLOCK_ID = $ib->Add($arFields);
            $res = ($IBLOCK_ID > 0);
        } else {
            $res = false;
        }

        if (!$res) {
            $strImportErrorMessage .= str_replace('#ERROR#', $ib->LAST_ERROR, str_replace('#NAME#', '['.$IBLOCK_ID.'] "'.$IBLOCK_NAME.'" ('.$IBLOCK_XML_ID.')', GetMessage('CICML_ERROR_ADDING_CATALOG'))).'.<br>';
            ++$STT_CATALOG_ERROR;
        } else {
            $boolIBlockTranslit = $USE_TRANSLIT;
            $boolTranslitElement = false;
            $boolTranslitSection = false;
            $arTranslitElement = [];
            $arTranslitSection = [];

            if ('Y' === $boolIBlockTranslit) {
                $boolOutTranslit = false;
                $arIBlock = CIBlock::GetArrayByID($IBLOCK_ID);
                if (isset($arIBlock['FIELDS']['CODE']['DEFAULT_VALUE'])) {
                    if ('Y' === $arIBlock['FIELDS']['CODE']['DEFAULT_VALUE']['TRANSLITERATION']
                        && 'Y' === $arIBlock['FIELDS']['CODE']['DEFAULT_VALUE']['USE_GOOGLE']) {
                        $boolOutTranslit = true;
                    }
                }
                if (isset($arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE'])) {
                    if ('Y' === $arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE']['TRANSLITERATION']
                        && 'Y' === $arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE']['USE_GOOGLE']) {
                        $boolOutTranslit = true;
                    }
                }
                if ($boolOutTranslit) {
                    $boolIBlockTranslit = 'N';
                    $strImportErrorMessage .= str_replace('#ERROR#', GetMessage('CATI_USE_CODE_TRANSLIT_OUT'), str_replace('#NAME#', '['.$IBLOCK_ID.'] "'.$IBLOCK_NAME.'" ('.$IBLOCK_XML_ID.')', GetMessage('CICML_ERROR_ADDING_CATALOG'))).'.<br>';
                    ++$STT_CATALOG_ERROR;

                    continue;
                }

                if ('Y' === $boolIBlockTranslit) {
                    if (isset($arIBlock['FIELDS']['CODE']['DEFAULT_VALUE'])) {
                        $arTransSettings = $arIBlock['FIELDS']['CODE']['DEFAULT_VALUE'];
                        $boolTranslitElement = ('Y' === $arTransSettings['TRANSLITERATION'] ? true : false);
                        $arTranslitElement = [
                            'max_len' => $arTransSettings['TRANS_LEN'],
                            'change_case' => $arTransSettings['TRANS_CASE'],
                            'replace_space' => $arTransSettings['TRANS_SPACE'],
                            'replace_other' => $arTransSettings['TRANS_OTHER'],
                            'delete_repeat_replace' => ('Y' === $arTransSettings['TRANS_EAT'] ? true : false),
                            'use_google' => ('Y' === $arTransSettings['USE_GOOGLE'] ? true : false),
                        ];
                    }
                    if (isset($arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE'])) {
                        $arTransSettings = $arIBlock['FIELDS']['SECTION_CODE']['DEFAULT_VALUE'];
                        $boolTranslitSection = ('Y' === $arTransSettings['TRANSLITERATION'] ? true : false);
                        $arTranslitSection = [
                            'max_len' => $arTransSettings['TRANS_LEN'],
                            'change_case' => $arTransSettings['TRANS_CASE'],
                            'replace_space' => $arTransSettings['TRANS_SPACE'],
                            'replace_other' => $arTransSettings['TRANS_OTHER'],
                            'delete_repeat_replace' => ('Y' === $arTransSettings['TRANS_EAT'] ? true : false),
                            'use_google' => ('Y' === $arTransSettings['USE_GOOGLE'] ? true : false),
                        ];
                    }
                }
            }

            if ($bNewRecord_tmp) {
                ++$STT_CATALOG_ADD;
            } else {
                $STT_CATALOG_UPDATE++;
            }

            $arCatalogs[$IBLOCK_XML_ID] = $IBLOCK_ID;

            if (!CCatalog::GetByID($IBLOCK_ID)) {
                CCatalog::Add(['IBLOCK_ID' => $IBLOCK_ID]);
            }

            $arProperties = [];
            $ibp = new CIBlockProperty();
            $props = $xCatNode->select_nodes('/'.$nameUTF['Property']);

            for ($j = 0, $intPropCount = count($props); $j < $intPropCount; ++$j) {
                $xPropNode = $props[$j];

                $PROP_XML_ID = $xPropNode->GetAttribute($nameUTF['ID']);
                $PROP_TYPE = $xPropNode->GetAttribute($nameUTF['DataType']);
                $PROP_MULTIPLE = ('1' === $xPropNode->GetAttribute($nameUTF['Multiple']) ? 'Y' : 'N');
                $PROP_NAME = $xPropNode->GetAttribute($nameUTF['Name']);
                $PROP_DEF = $xPropNode->GetAttribute($nameUTF['DefaultValue']);
                if ('enumeration' === $PROP_TYPE) {
                    $PROP_TYPE = 'L';
                } else {
                    $PROP_TYPE = 'S';
                }

                if ($arPriceType[$IBLOCK_XML_ID] === $PROP_XML_ID) {
                    continue;
                }

                $res = CIBlock::GetProperties($IBLOCK_ID, [], ['IBLOCK_ID' => $IBLOCK_ID, 'XML_ID' => $PROP_XML_ID]);
                $bNewRecord_tmp = false;
                if ($res_arr = $res->Fetch()) {
                    $PROP_ID = $res_arr['ID'];
                    $res = $ibp->Update(
                        $PROP_ID,
                        [
                            'NAME' => $PROP_NAME,
                            'TYPE' => $PROP_TYPE,
                            'MULTIPLE' => $PROP_MULTIPLE,
                            'DEFAULT_VALUE' => $PROP_DEF,
                            'TMP_ID' => $tmpid,
                        ]
                    );
                } else {
                    $bNewRecord_tmp = true;
                    $arFields = [
                        'NAME' => $PROP_NAME,
                        'ACTIVE' => 'Y',
                        'SORT' => '500',
                        'DEFAULT_VALUE' => $PROP_DEF,
                        'XML_ID' => $PROP_XML_ID,
                        'TMP_ID' => $tmpid,
                        'MULTIPLE' => $PROP_MULTIPLE,
                        'PROPERTY_TYPE' => $PROP_TYPE,
                        'IBLOCK_ID' => $IBLOCK_ID,
                    ];
                    $PROP_ID = $ibp->Add($arFields);
                    $res = ((int) $PROP_ID > 0);
                }

                if (!$res) {
                    $strImportErrorMessage .= str_replace('#ERROR#', $ibp->LAST_ERROR, str_replace('#NAME#', '['.$PROP_ID.'] "'.$PROP_NAME.'" ('.$PROP_XML_ID.')', GetMessage('CICML_ERROR_ADD_PROPS'))).'.<br>';
                    ++$STT_PROP_ERROR;
                } else {
                    if ($bNewRecord_tmp) {
                        ++$STT_PROP_ADD;
                    } else {
                        $STT_PROP_UPDATE++;
                    }

                    $arProperties[$PROP_XML_ID] = $PROP_ID;
                }

                if ('L' === $PROP_TYPE) {
                    $pren = new CIBlockPropertyEnum();
                    $arPropertiesEnum[$PROP_XML_ID] = [];
                    $prop_enums = $xPropNode->select_nodes('/'.$nameUTF['PropertyVariant']);
                    for ($k = 0, $intEnumPropCount = count($prop_enums); $k < $intEnumPropCount; ++$k) {
                        $xPropEnum = $prop_enums[$k];
                        $PROP_ENUM_XML_ID = $xPropEnum->GetAttribute($nameUTF['ID']);
                        $PROP_ENUM_NAME = $xPropEnum->GetAttribute($nameUTF['Name']);
                        $PROP_ENUM_DEF = ($PROP_ENUM_XML_ID === $PROP_DEF ? 'Y' : 'N');

                        $arFields = [
                            'DEF' => $PROP_ENUM_DEF,
                            'TMP_ID' => $tmpid,
                            'VALUE' => $PROP_ENUM_NAME,
                            'PROPERTY_ID' => $PROP_ID,
                            'XML_ID' => $PROP_ENUM_XML_ID,
                        ];
                        $res = CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $PROP_ID, 'XML_ID' => $PROP_ENUM_XML_ID]);
                        if ($arr = $res->Fetch()) {
                            $PROP_ENUM_ID = $arr['ID'];
                            $pren->Update($PROP_ENUM_ID, $arFields);
                        } else {
                            $PROP_ENUM_ID = $pren->Add($arFields);
                        }
                        $arPropertiesEnum[$PROP_XML_ID][$PROP_ENUM_XML_ID] = $PROP_ENUM_ID;
                    }
                }
            }

            if (function_exists('catalog_property_mutator_1c')) {
                catalog_property_mutator_1c();
            }

            $arGrTmp = [];
            $groups = $xCatNode->select_nodes('/'.$nameUTF['Category']);
            for ($j = 0, $intGroupCount = count($groups); $j < $intGroupCount; ++$j) {
                $xGroupNode = $groups[$j];

                $GROUP_XML_ID = $xGroupNode->GetAttribute($nameUTF['ID']);
                $GROUP_PARENT_XML_ID = $xGroupNode->GetAttribute($nameUTF['ParentCategory']);
                $GROUP_NAME = $xGroupNode->GetAttribute($nameUTF['Name']);

                $arGrTmp[$tmpid.$GROUP_PARENT_XML_ID][] = [
                    'GROUP_XML_ID' => $GROUP_XML_ID,
                    'GROUP_PARENT_XML_ID' => $GROUP_PARENT_XML_ID,
                    'GROUP_NAME' => $GROUP_NAME,
                ];
            }

            $arGroups = [];
            AddSectionsRecursive();
            CIBlockSection::ReSort($IBLOCK_ID);

            $el = new CIBlockElement();
            $arProducts = [];
            $products = $xCatNode->select_nodes('/'.$nameUTF['Product']);
            for ($j = 0, $intProdCount = count($products); $j < $intProdCount; ++$j) {
                $xProductNode = $products[$j];

                $PRODUCT_XML_ID = $xProductNode->GetAttribute($nameUTF['ID']);
                $PRODUCT_PARENT_XML_ID = $xProductNode->GetAttribute($nameUTF['ParentCategory']);
                $PRODUCT_NAME = $xProductNode->GetAttribute($nameUTF['Name']);

                $PROP = [];
                $GROUPS_ID = [];
                if ('' !== $PRODUCT_PARENT_XML_ID && '' !== $arGroups[$PRODUCT_PARENT_XML_ID]) {
                    $GROUPS_ID[] = $arGroups[$PRODUCT_PARENT_XML_ID];
                }

                $prod_groups = $xProductNode->select_nodes('/'.$nameUTF['CategoryReference']);
                for ($k = 0, $intProdGroupCount = count($prod_groups); $k < $intProdGroupCount; ++$k) {
                    $xProductGroupsNode = $prod_groups[$k];
                    $PRODUCT_GROUP_XML_ID = $xProductGroupsNode->GetAttribute($nameUTF['IdInCatalog']);
                    if ('' !== $arGroups[$PRODUCT_GROUP_XML_ID]) {
                        $GROUPS_ID[] = $arGroups[$PRODUCT_GROUP_XML_ID];
                    }
                }

                $prop_vals = $xProductNode->select_nodes('/'.$nameUTF['PropertyValue']);
                for ($k = 0, $intPropValCount = count($prop_vals); $k < $intPropValCount; ++$k) {
                    $xPropertyValueNode = $prop_vals[$k];

                    $PROP_VAL_PROPERTY_XML_ID = $xPropertyValueNode->GetAttribute($nameUTF['PropertyId']);
                    $PROP_VAL_VALUE = $xPropertyValueNode->GetAttribute($nameUTF['Value']);
                    if ('' !== $arProperties[$PROP_VAL_PROPERTY_XML_ID]) {
                        if (is_array($arPropertiesEnum[$PROP_VAL_PROPERTY_XML_ID])) {
                            if ('' !== $arPropertiesEnum[$PROP_VAL_PROPERTY_XML_ID][$PROP_VAL_VALUE]) {
                                $PROP[$arProperties[$PROP_VAL_PROPERTY_XML_ID]][] = $arPropertiesEnum[$PROP_VAL_PROPERTY_XML_ID][$PROP_VAL_VALUE];
                            }
                        } else {
                            $PROP[$arProperties[$PROP_VAL_PROPERTY_XML_ID]][] = $PROP_VAL_VALUE;
                        }
                    }
                }

                $arLoadProductArray = [
                    'MODIFIED_BY' => $USER->GetID(),
                    'IBLOCK_SECTION' => $GROUPS_ID,
                    'IBLOCK_ID' => $IBLOCK_ID,
                    'NAME' => $PRODUCT_NAME,
                    'XML_ID' => $PRODUCT_XML_ID,
                    'TMP_ID' => $tmpid,
                    'PROPERTY_VALUES' => $PROP,
                ];
                if ($boolTranslitElement) {
                    $arLoadProductArray['CODE'] = CUtil::translit($PRODUCT_NAME, 'ru', $arTranslitElement);
                }

                $res = CIBlockElement::GetList([], ['IBLOCK_ID' => $IBLOCK_ID, 'XML_ID' => $PRODUCT_XML_ID]);
                $bNewRecord_tmp = false;
                if ($arr = $res->Fetch()) {
                    $PRODUCT_ID = $arr['ID'];
                    if ('Y' === $activateFileData) {
                        $arLoadProductArray['ACTIVE'] = 'Y';
                    }
                    if (function_exists('catalog_product_mutator_1c')) {
                        $arLoadProductArray = catalog_product_mutator_1c($arLoadProductArray, $xProductNode, false);
                    }
                    $res = $el->Update($PRODUCT_ID, $arLoadProductArray);
                } else {
                    $bNewRecord_tmp = true;
                    $arLoadProductArray['ACTIVE'] = 'Y';
                    if (function_exists('catalog_product_mutator_1c')) {
                        $arLoadProductArray = catalog_product_mutator_1c($arLoadProductArray, $xProductNode, true);
                    }
                    $PRODUCT_ID = $el->Add($arLoadProductArray);
                    $res = ($PRODUCT_ID > 0);
                }

                if (!$res) {
                    $strImportErrorMessage .= str_replace('#ERROR#', $el->LAST_ERROR, str_replace('#NAME#', '['.$PRODUCT_ID.'] "'.$PRODUCT_NAME.'" ('.$PRODUCT_XML_ID.')', GetMessage('CICML_ERROR_ADDING_PRODUCT'))).'.<br>';
                    ++$STT_PRODUCT_ERROR;
                } else {
                    if ($bNewRecord_tmp) {
                        ++$STT_PRODUCT_ADD;
                    } else {
                        $STT_PRODUCT_UPDATE++;
                    }

                    $arProducts[$PRODUCT_XML_ID] = $PRODUCT_ID;
                }

                $arCatalogsParams[$IBLOCK_XML_ID] = [
                    'arProperties' => $arProperties,
                    'arPropertiesEnum' => $arPropertiesEnum,
                    'arGroups' => $arGroups,
                    'arProducts' => $arProducts,
                ];
            }

            if ('N' === $keepExistingProperties) {
                $res = CIBlockProperty::GetList([], ['IBLOCK_ID' => $IBLOCK_ID, '!TMP_ID' => $tmpid]);
                while ($arr = $res->Fetch()) {
                    CIBlockProperty::Delete($arr['ID']);
                }

                $res = CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => $IBLOCK_ID, '!TMP_ID' => $tmpid]);
                while ($arr = $res->Fetch()) {
                    CIBlockPropertyEnum::Delete($arr['ID']);
                }
            }

            if ('F' !== $outFileAction) {
                $bs = new CIBlockSection();
                $res = CIBlockSection::GetList([], ['IBLOCK_ID' => $IBLOCK_ID, '!TMP_ID' => $tmpid]);
                while ($arr = $res->Fetch()) {
                    if ('H' !== $outFileAction) {
                        CIBlockSection::Delete($arr['ID']);
                    } else {
                        $bs->Update($arr['ID'], ['NAME' => $arr['NAME'], 'ACTIVE' => 'N']);
                    }
                }

                $res = CIBlockElement::GetList([], ['IBLOCK_ID' => $IBLOCK_ID, '!TMP_ID' => $tmpid]);
                while ($arr = $res->Fetch()) {
                    if ('H' !== $outFileAction) {
                        CIBlockElement::Delete($arr['ID'], 'Y', 'N');
                    } else {
                        $el->Update($arr['ID'], ['ACTIVE' => 'N']);
                    }
                }
            }
        }
    }

    global $arCurrencies;
    $arCurrencies = [];

    include __DIR__.'/ru/commerceml_run_cur.php';
    if (!isset($arCurrencies) || !is_array($arCurrencies) || empty($arCurrencies)) {
        $strImportErrorMessage .= GetMessage('CAT_ADM_CML1_IMP_ERR_CMLCUR').'<br>';
    }

    //	$arProductsTmp = array();
    //	foreach ($arCatalogsParams as $key=>$Val)
    //	{
    //		$arProductsTmp[$key] = $Val["arProducts"];
    //	}
    //	$arProductsTmp = $arCatalogsParams[$IBLOCK_XML_ID]["arProducts"];
    $arProductsTmpA = $arCatalogsParams;

    for ($i = 0, $intOFListCount = count($offerlists); $i < $intOFListCount; ++$i) {
        $xOfferListNode = $offerlists[$i];

        $IBLOCK_XML_ID = $xOfferListNode->GetAttribute($nameUTF['CatalogID']);
        $OFFER_LIST_DESC = $xOfferListNode->GetAttribute($nameUTF['Description']);
        $OFFER_LIST_CURRENCY = $arCurrencies[$xOfferListNode->GetAttribute($nameUTF['Currency'])];
        if ('' === $OFFER_LIST_CURRENCY) {
            $OFFER_LIST_CURRENCY = 'USD';
        }

        // detect "price type" property
        $props = $xOfferListNode->select_nodes('/'.$nameUTF['PropertyValue']);
        if (!is_object($props[0])) {
            continue;
        }

        $PRICE_TYPE = $props[0]->GetAttribute($nameUTF['Value']);
        $res = CCatalogGroup::GetList([], ['NAME' => $PRICE_TYPE]);
        if ($arr = $res->Fetch()) {
            $PRICE_ID = $arr['ID'];
        } else {
            $PRICE_ID = CCatalogGroup::Add(
                [
                    'NAME' => $PRICE_TYPE,
                    'USER_LANG' => ['ru' => $PRICE_TYPE],
                ]
            );
        }

        $arProducts = $arCatalogsParams[$IBLOCK_XML_ID]['arProducts'];

        $arOffers = [];
        $offers = $xOfferListNode->select_nodes('/'.$nameUTF['Offer']);

        for ($j = 0, $intOtOFCount = count($offers); $j < $intOtOFCount; ++$j) {
            $xOfferNode = $offers[$j];

            $PRODUCT_XML_ID = $xOfferNode->GetAttribute($nameUTF['ProductId']);

            // we sholdn't go to 'continue', but should search product in DB by XML_ID
            // and in array if absent. Then we'll be able to load only prices without products
            $PRODUCT_ID = 0;
            if (!isset($arProducts[$PRODUCT_XML_ID])) {
                if (!isset($arIBlockCacheTmp[$IBLOCK_XML_ID])
                    || (int) $arIBlockCacheTmp[$IBLOCK_XML_ID] <= 0) {
                    $db_res_tmp = CIBlock::GetList([], ['XML_ID' => $IBLOCK_XML_ID]);
                    if ($ar_res_tmp = $db_res_tmp->Fetch()) {
                        $arIBlockCacheTmp[$IBLOCK_XML_ID] = (int) $ar_res_tmp['ID'];
                    }
                }

                $db_res_tmp = CIBlockElement::GetList([], ['XML_ID' => $PRODUCT_XML_ID, 'IBLOCK_ID' => $arIBlockCacheTmp[$IBLOCK_XML_ID]]);
                if ($ar_res_tmp = $db_res_tmp->Fetch()) {
                    $PRODUCT_ID = (int) $ar_res_tmp['ID'];
                    $arCatalogsParams[$IBLOCK_XML_ID]['arProducts'][$PRODUCT_XML_ID] = $PRODUCT_ID;
                }
            } else {
                $PRODUCT_ID = (int) $arProducts[$PRODUCT_XML_ID];
            }

            if ($PRODUCT_ID <= 0) {
                continue;
            }

            unset($arProductsTmpA[$IBLOCK_XML_ID]['arProducts'][$PRODUCT_XML_ID]);

            $OFFER_PRICE = (float) str_replace(',', '.', $xOfferNode->GetAttribute($nameUTF['Price']));
            $OFFER_UPAK = $xOfferNode->GetAttribute($nameUTF['Package']);
            $OFFER_QUANTITY = (int) $xOfferNode->GetAttribute($nameUTF['Amount']);
            $OFFER_UNIT = $xOfferNode->GetAttribute($nameUTF['Unit']);
            $OFFER_CURRENCY = $arCurrencies[$xOfferNode->GetAttribute($nameUTF['Currency'])];
            if ('' === $OFFER_CURRENCY) {
                $OFFER_CURRENCY = $OFFER_LIST_CURRENCY;
            }

            $arLoadOfferArray = [
                'ID' => $PRODUCT_ID,
                'QUANTITY' => $OFFER_QUANTITY,
            ];
            if (function_exists('catalog_offer_mutator_1c')) {
                $arLoadOfferArray = catalog_offer_mutator_1c($arLoadOfferArray, $xOfferNode);
            }

            $khjk = CCatalogProduct::Add($arLoadOfferArray);

            $arFields = [
                'PRODUCT_ID' => $PRODUCT_ID,
                'CATALOG_GROUP_ID' => $PRICE_ID,
                'PRICE' => $OFFER_PRICE,
                'CURRENCY' => $OFFER_CURRENCY,
            ];

            $res = CPrice::GetList(
                [],
                [
                    'PRODUCT_ID' => $PRODUCT_ID,
                    'CATALOG_GROUP_ID' => $PRICE_ID,
                ]
            );
            if ($arr = $res->Fetch()) {
                $khjk = CPrice::Update($arr['ID'], $arFields);
            } else {
                $khjk = CPrice::Add($arFields);
            }
        }
    }

    if ('Y' === COption::GetOptionString('catalog', 'deactivate_1c_no_price', 'N')) {
        foreach ($arProductsTmpA as $keyA => $valA) {
            foreach ($valA['arProducts'] as $keyB => $valB) {
                $res = $el->Update((int) $valB, ['ACTIVE' => 'N']);
            }
        }
    }

    $strImportOKMessage .= str_replace('#TIME#', RoundEx(getmicrotime() - $startImportExecTime, 2), GetMessage('CICML_LOAD_TIME')).'<br>';
    $strImportOKMessage .= str_replace('#NUM#', $STT_CATALOG_UPDATE + $STT_CATALOG_ADD, GetMessage('CICML_LOAD_CATALOG')).' ';
    $strImportOKMessage .= str_replace('#NUM_UPD#', $STT_CATALOG_UPDATE, str_replace('#NUM_NEW#', $STT_CATALOG_ADD, GetMessage('CICML_LOAD_NEW_UPD'))).' ';

    if ((int) $STT_CATALOG_ERROR > 0) {
        $strImportOKMessage .= str_replace('#NUM#', $STT_CATALOG_ERROR, GetMessage('CICML_LOAD_ERROR'));
    }
    $strImportOKMessage .= '<br>';

    $strImportOKMessage .= str_replace('#NUM#', $STT_GROUP_UPDATE + $STT_GROUP_ADD, GetMessage('CICML_LOAD_GROUP')).' ';
    $strImportOKMessage .= str_replace('#NUM_UPD#', $STT_GROUP_UPDATE, str_replace('#NUM_NEW#', $STT_GROUP_ADD, GetMessage('CICML_LOAD_GROUP_NEW_UPD'))).' ';

    if ((int) $STT_GROUP_ERROR > 0) {
        $strImportOKMessage .= str_replace('#NUM#', $STT_GROUP_ERROR, GetMessage('CICML_LOAD_GROUP_ERROR'));
    }
    $strImportOKMessage .= '<br>';

    $strImportOKMessage .= str_replace('#NUM#', $STT_PROP_UPDATE + $STT_PROP_ADD, GetMessage('CICML_LOAD_PROPS')).' ';
    $strImportOKMessage .= str_replace('#NUM_UPD#', $STT_PROP_UPDATE, str_replace('#NUM_NEW#', $STT_PROP_ADD, GetMessage('CICML_LOAD_PROPS_NEW_UPD'))).' ';

    if ((int) $STT_PROP_ERROR > 0) {
        $strImportOKMessage .= str_replace('#NUM#', $STT_PROP_ERROR, GetMessage('CICML_LOAD_PROPS_ERROR'));
    }
    $strImportOKMessage .= '<br>';

    $strImportOKMessage .= str_replace('#NUM#', $STT_PRODUCT_UPDATE + $STT_PRODUCT_ADD, GetMessage('CICML_LOAD_PROD')).' ';
    $strImportOKMessage .= str_replace('#NUM_UPD#', $STT_PRODUCT_UPDATE, str_replace('#NUM_NEW#', $STT_PRODUCT_ADD, GetMessage('CICML_LOAD_PROD_NEW_UPD'))).' ';

    if ((int) $STT_PRODUCT_ERROR > 0) {
        $strImportOKMessage .= str_replace('#NUM#', $STT_PRODUCT_ERROR, GetMessage('CICML_LOAD_PROD_ERROR'));
    }
    $strImportOKMessage .= '<br>';
}

if ($bTmpUserCreated) {
    unset($USER);
    if (isset($USER_TMP)) {
        $USER = $USER_TMP;
        unset($USER_TMP);
    }
}
