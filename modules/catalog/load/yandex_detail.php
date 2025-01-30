<?php
/** @global CDatabase $DB */
// @global CUser $USER
// @global CMain $APPLICATION
use Bitrix\Iblock;

// define("STOP_STATISTICS", true);
// define("BX_SECURITY_SHOW_MESSAGE", true);
// define('NO_AGENT_CHECK', true);

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';
IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/catalog/export_yandex.php');

if ('GET' === $_SERVER['REQUEST_METHOD']) {
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';

    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

    exit;
}

if (!check_bitrix_sessid()) {
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
    $APPLICATION->AuthForm(GetMessage('ACCESS_DENIED'));

    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

    exit;
}

$APPLICATION->SetTitle(GetMessage('YANDEX_DETAIL_TITLE'));

CModule::IncludeModule('catalog');

if (!$USER->CanDoOperation('catalog_export_edit')) {
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError(GetMessage('YANDEX_ERR_NO_ACCESS_EXPORT'));

    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

    exit;
}

if ((!isset($_REQUEST['IBLOCK_ID'])) || ('' === $_REQUEST['IBLOCK_ID'])) {
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError(GetMessage('YANDEX_ERR_NO_IBLOCK_CHOSEN'));

    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

    exit;
}
$intIBlockID = $_REQUEST['IBLOCK_ID'];
$intIBlockIDCheck = (int) $intIBlockID;
if ($intIBlockIDCheck.'|' !== $intIBlockID.'|' || $intIBlockIDCheck <= 0) {
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError(GetMessage('YANDEX_ERR_NO_IBLOCK_CHOSEN'));

    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

    exit;
}

$intIBlockID = $intIBlockIDCheck;
unset($intIBlockIDCheck);

$strPerm = 'D';
$rsIBlocks = CIBlock::GetByID($intIBlockID);
if ($arIBlock = $rsIBlocks->Fetch()) {
    $bBadBlock = !CIBlockRights::UserHasRightTo($intIBlockID, $intIBlockID, 'iblock_admin_display');
    if ($bBadBlock) {
        require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
        ShowError(GetMessage('YANDEX_ERR_NO_ACCESS_IBLOCK'));

        require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

        exit;
    }
} else {
    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError(str_replace('#ID#', $intIBlockID, GetMessage('YANDEX_ERR_NO_IBLOCK_FOUND_EXT')));

    require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

    exit;
}

$boolOffers = false;
$arOffers = false;
$arOfferIBlock = false;
$intOfferIBlockID = 0;
$arSelectOfferProps = [];
$arSelectedPropTypes = [
    Iblock\PropertyTable::TYPE_STRING,
    Iblock\PropertyTable::TYPE_NUMBER,
    Iblock\PropertyTable::TYPE_LIST,
    Iblock\PropertyTable::TYPE_ELEMENT,
    Iblock\PropertyTable::TYPE_SECTION,
];
$arOffersSelectKeys = [
    YANDEX_SKU_EXPORT_ALL,
    YANDEX_SKU_EXPORT_MIN_PRICE,
    YANDEX_SKU_EXPORT_PROP,
];

$arOffers = CCatalogSKU::GetInfoByProductIBlock($intIBlockID);
if (!empty($arOffers['IBLOCK_ID'])) {
    $intOfferIBlockID = $arOffers['IBLOCK_ID'];
    $strPerm = 'D';
    $rsOfferIBlocks = CIBlock::GetByID($intOfferIBlockID);
    if ($arOfferIBlock = $rsOfferIBlocks->Fetch()) {
        $bBadBlock = !CIBlockRights::UserHasRightTo($intOfferIBlockID, $intOfferIBlockID, 'iblock_admin_display');
        if ($bBadBlock) {
            require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
            ShowError(GetMessage('YANDEX_ERR_NO_ACCESS_IBLOCK_SKU'));

            require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

            exit;
        }
    } else {
        require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
        ShowError(str_replace('#ID#', $intIBlockID, GetMessage('YANDEX_ERR_NO_IBLOCK_SKU_FOUND')));

        require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

        exit;
    }
    $boolOffers = true;
}
$arCondSelectProp = [
    'ZERO' => GetMessage('YANDEX_SKU_EXPORT_PROP_SELECT_ZERO'),
    'NONZERO' => GetMessage('YANDEX_SKU_EXPORT_PROP_SELECT_NONZERO'),
    'EQUAL' => GetMessage('YANDEX_SKU_EXPORT_PROP_SELECT_EQUAL'),
    'NONEQUAL' => GetMessage('YANDEX_SKU_EXPORT_PROP_SELECT_NONEQUAL'),
];

$arTypesConfig = [
    'none' => [
        'vendor', 'vendorCode', 'sales_notes', 'manufacturer_warranty', 'country_of_origin',
        // 'adult'
    ],
    'vendor.model' => [
        'typePrefix', 'vendor', 'vendorCode', 'model', 'sales_notes', 'manufacturer_warranty', 'country_of_origin',
        // 'adult'
    ],
    'book' => [
        'author', 'publisher', 'series', 'year', 'ISBN', 'volume', 'part', 'language', 'binding',
        'page_extent', 'table_of_contents',
    ],
    'audiobook' => [
        'author', 'publisher', 'series', 'year', 'ISBN', 'performed_by', 'performance_type',
        'language', 'volume', 'part', 'format', 'storage', 'recording_length', 'table_of_contents',
    ],
    'artist.title' => [
        'title', 'artist', 'director', 'starring', 'originalName', 'country', 'year', 'media', // 'adult'
    ],

    // a bit later
    /*
    'tour' => array(
        'worldRegion', 'country', 'region', 'days', 'dataTour', 'hotel_stars', 'room', 'meal', 'included', 'transport',
    ),
    'event-ticket' => array(
        'place', 'hall', 'date', 'is_premiere', 'is_kids',
    ),
*/
];

$arTypesConfigKeys = array_keys($arTypesConfig);

$dbRes = CIBlockProperty::GetList(
    ['sort' => 'asc'],
    ['IBLOCK_ID' => $intIBlockID, 'ACTIVE' => 'Y']
);
$arIBlock['PROPERTY'] = [];
$arIBlock['OFFERS_PROPERTY'] = [];
while ($arRes = $dbRes->Fetch()) {
    $arIBlock['PROPERTY'][$arRes['ID']] = $arRes;
}
if ($boolOffers) {
    $rsProps = CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $intOfferIBlockID, 'ACTIVE' => 'Y']);
    while ($arProp = $rsProps->Fetch()) {
        if ($arOffers['SKU_PROPERTY_ID'] !== $arProp['ID']) {
            if ('L' === $arProp['PROPERTY_TYPE']) {
                $arProp['VALUES'] = [];
                $rsPropEnums = CIBlockProperty::GetPropertyEnum($arProp['ID'], ['sort' => 'asc'], ['IBLOCK_ID' => $intOfferIBlockID]);
                while ($arPropEnum = $rsPropEnums->Fetch()) {
                    $arProp['VALUES'][$arPropEnum['ID']] = $arPropEnum['VALUE'];
                }
            }
            $arIBlock['OFFERS_PROPERTY'][$arProp['ID']] = $arProp;
            if (in_array($arProp['PROPERTY_TYPE'], $arSelectedPropTypes, true)) {
                $arSelectOfferProps[] = $arProp['ID'];
            }
        }
    }
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    if (!empty($_REQUEST['save'])) {
        $arErrors = [];
        $arCurrency = ['RUB' => ['rate' => 1]];
        if (is_array($_POST['CURRENCY']) && count($_POST['CURRENCY']) > 0) {
            $arCurrency = [];
            foreach ($_POST['CURRENCY'] as $CURRENCY) {
                $arCurrency[$CURRENCY] = [
                    'rate' => $_POST['CURRENCY_RATE'][$CURRENCY],
                    'plus' => $_POST['CURRENCY_PLUS'][$CURRENCY],
                ];
            }
        }

        $type = trim($_POST['type']);
        if ('none' !== $type && !in_array($type, $arTypesConfigKeys, true)) {
            $type = 'none';
        }

        $addParams = [
            'PARAMS' => [],
        ];
        if (isset($_POST['PARAMS_COUNT']) && (int) $_POST['PARAMS_COUNT'] > 0) {
            $intCount = (int) $_POST['PARAMS_COUNT'];
            if (isset($_POST['XML_DATA']['PARAMS']) && is_array($_POST['XML_DATA']['PARAMS'])) {
                $arTempo = $_POST['XML_DATA']['PARAMS'];
                for ($i = 0; $i < $intCount; ++$i) {
                    if (empty($arTempo['ID_'.$i])) {
                        continue;
                    }
                    $value = $arTempo['ID_'.$i];
                    if (array_key_exists($value, $arIBlock['PROPERTY']) || array_key_exists($value, $arIBlock['OFFERS_PROPERTY'])) {
                        $addParams['PARAMS'][] = $value;
                    }
                }
            }
        }

        $arTypeParams = [];
        if (isset($_POST['XML_DATA'][$type]) && is_array($_POST['XML_DATA'][$type])) {
            $arTypeParams = $_POST['XML_DATA'][$type];
            foreach ($arTypeParams as $key => $value) {
                if (!in_array($key, $arTypesConfig[$type], true)) {
                    unset($arTypeParams[$key]);
                } elseif (!array_key_exists($value, $arIBlock['PROPERTY']) && !array_key_exists($value, $arIBlock['OFFERS_PROPERTY'])) {
                    $arTypeParams[$key] = '';
                }
            }
        }
        $XML_DATA = array_merge($arTypeParams, $addParams);

        foreach ($XML_DATA as $key => $value) {
            if (!$value) {
                unset($XML_DATA[$key]);
            }
        }

        $arSKUExport = false;
        if ($boolOffers) {
            $arSKUExport = [
                'SKU_URL_TEMPLATE_TYPE' => YANDEX_SKU_TEMPLATE_PRODUCT,
                'SKU_URL_TEMPLATE' => '',
                'SKU_EXPORT_COND' => YANDEX_SKU_EXPORT_ALL,
                'SKU_PROP_COND' => [
                    'PROP_ID' => 0,
                    'COND' => '',
                    'VALUES' => [],
                ],
            ];

            if (!empty($_POST['SKU_EXPORT_COND']) && in_array($_POST['SKU_EXPORT_COND'], $arOffersSelectKeys, true)) {
                $arSKUExport['SKU_EXPORT_COND'] = $_POST['SKU_EXPORT_COND'];
            } else {
                $arErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_CONDITION_ABSENT');
            }
            if (YANDEX_SKU_EXPORT_PROP === $arSKUExport['SKU_EXPORT_COND']) {
                $boolCheck = true;
                $intPropID = 0;
                $strPropCond = '';
                $arPropValues = [];
                if (empty($_POST['SKU_PROP_COND']) || !in_array($_POST['SKU_PROP_COND'], $arSelectOfferProps, true)) {
                    $arErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_PROPERTY_ABSENT');
                    $boolCheck = false;
                }
                if ($boolCheck) {
                    $intPropID = $_POST['SKU_PROP_COND'];
                    if (empty($_POST['SKU_PROP_SELECT']) || empty($arCondSelectProp[$_POST['SKU_PROP_SELECT']])) {
                        $arErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_PROPERTY_COND_ABSENT');
                        $boolCheck = false;
                    }
                }
                if ($boolCheck) {
                    $strPropCond = $_POST['SKU_PROP_SELECT'];
                    if ('EQUAL' === $strPropCond || 'NONEQUAL' === $strPropCond) {
                        if (!isset($_POST['SKU_PROP_VALUE_'.$intPropID]) || !is_array($_POST['SKU_PROP_VALUE_'.$intPropID])) {
                            $arErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_PROPERTY_VALUES_ABSENT');
                            $boolCheck = false;
                        }

                        if ($boolCheck) {
                            foreach ($_POST['SKU_PROP_VALUE_'.$intPropID] as $strValue) {
                                if ('' !== $strValue) {
                                    $arPropValues[] = $strValue;
                                }
                            }
                        }
                        if (empty($arPropValues)) {
                            $arErrors[] = GetMessage('YANDEX_SKU_EXPORT_ERR_PROPERTY_VALUES_ABSENT');
                            $boolCheck = false;
                        }
                    }
                }
                if ($boolCheck) {
                    $arSKUExport['SKU_PROP_COND'] = [
                        'PROP_ID' => $intPropID,
                        'COND' => $strPropCond,
                        'VALUES' => $arPropValues,
                    ];
                }
            }
        }

        if (empty($arErrors)) {
            $arXMLData = [
                'TYPE' => $type,
                'XML_DATA' => $XML_DATA,
                'CURRENCY' => $arCurrency,
                'PRICE' => (int) $_POST['PRICE'],
                'SKU_EXPORT' => $arSKUExport,
            ];
            ?><script type="text/javascript">
top.BX.closeWait();
top.BX.WindowManager.Get().Close();
top.setDetailData('<?php echo CUtil::JSEscape(base64_encode(serialize($arXMLData))); ?>');
</script>
<?php
                        exit;
        }

        $e = new CAdminException([['text' => implode("\n", $arErrors)]]);
        $message = new CAdminMessage(GetMessage('YANDEX_SAVE_ERR'), $e);
        echo $message->Show();
    } else {
        /*if ($strError)
        {
        ?>
        <script type="text/javascript">
        var obDialog = BX.WindowManager.Get();
        obDialog.Close();
        obDialog.ShowError('<?=CUtil::JSEscape($strError);?>');
        </script>
        <?
            die();
        }*/

        $aTabs = [
            ['DIV' => 'edit1', 'TAB' => GetMessage('YANDEX_TAB1_TITLE'), 'TITLE' => GetMessage('YANDEX_TAB1_DESC')],
            ['DIV' => 'edit2', 'TAB' => GetMessage('YANDEX_TAB2_TITLE'), 'TITLE' => GetMessage('YANDEX_TAB2_DESC')],
        ];
        $tabControl = new CAdminTabControl('tabControl', $aTabs, true, true);

        require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';

        function __yand_show_selector($group, $key, $IBLOCK, $value = '')
        {
            ?><select name="XML_DATA[<?php echo htmlspecialcharsbx($group); ?>][<?php echo htmlspecialcharsbx($key); ?>]">
			<option value=""<?php echo '' === $value ? ' selected' : ''; ?>><?php echo GetMessage('YANDEX_SKIP_PROP'); ?></option>
			<?php
            if (!empty($IBLOCK['OFFERS_PROPERTY'])) {
                ?><option value=""><?php echo GetMessage('YANDEX_PRODUCT_PROPS'); ?></option><?php
            }
            foreach ($IBLOCK['PROPERTY'] as $key => $arProp) {
                ?><option value="<?php echo $arProp['ID']; ?>"<?php echo $value === $arProp['ID'] ? ' selected' : ''; ?>>[<?php echo htmlspecialcharsbx($key); ?>] <?php echo htmlspecialcharsbx($arProp['NAME']); ?></option><?php
            }
            if (!empty($IBLOCK['OFFERS_PROPERTY'])) {
                ?><option value=""><?php echo GetMessage('YANDEX_OFFERS_PROPS'); ?></option><?php
                foreach ($IBLOCK['OFFERS_PROPERTY'] as $key => $arProp) {
                    ?><option value="<?php echo $arProp['ID']; ?>"<?php echo $value === $arProp['ID'] ? ' selected' : ''; ?>>[<?php echo htmlspecialcharsbx($key); ?>] <?php echo htmlspecialcharsbx($arProp['NAME']); ?></option><?php
                }
            }
            ?></select><?php
        }

        function __addParamCode()
        {
            return '<small>(param)</small>';
        }

        function __addParamName(&$IBLOCK, $intCount, $value)
        {
            $strResult = '';
            ob_start();
            __yand_show_selector('PARAMS', 'ID_'.$intCount, $IBLOCK, $value);
            $strResult = ob_get_contents();
            ob_end_clean();

            return $strResult;
        }

        function __addParamUnit(&$IBLOCK, $intCount, $value)
        {
            return '<input type="text" size="3" name="XML_DATA[PARAMS][UNIT_'.$intCount.']" value="'.htmlspecialcharsbx($value).'">';
        }

        function __addParamRow(&$IBLOCK, $intCount, $strParam, $strUnit)
        {
            return '<tr id="yandex_params_tbl_'.$intCount.'">
				<td style="text-align: center;">'.__addParamCode().'</td>
				<td>'.__addParamName($IBLOCK, $intCount, $strParam).'</td>
				</tr>';
        }

        // HTML form
        $type = 'none';
        $arTypeValues = [];
        foreach ($arTypesConfigKeys as $key) {
            $arTempo = [];
            foreach ($arTypesConfig[$key] as $value) {
                $arTempo[$value] = '';
            }
            $arTypeValues[$key] = $arTempo;
        }
        $arAddParams = [];
        $params = [
            'PARAMS' => [],
        ];
        $PRICE = 0;
        $CURRENCY = [];
        $arSKUExport = [
            'SKU_URL_TEMPLATE_TYPE' => YANDEX_SKU_TEMPLATE_PRODUCT,
            'SKU_URL_TEMPLATE' => '',
            'SKU_EXPORT_COND' => 0,
            'SKU_PROP_COND' => [
                'PROP_ID' => 0,
                'COND' => '',
                'VALUES' => [],
            ],
        ];

        $arXmlData = [];
        if (isset($_REQUEST['XML_DATA'])) {
            $strXmlData = '';
            if ('' !== $_REQUEST['XML_DATA']) {
                $strXmlData = base64_decode($_REQUEST['XML_DATA'], true);
                if (true === CheckSerializedData($strXmlData)) {
                    $arXmlData = unserialize($strXmlData);
                }
            }
        }

        if (isset($arXmlData['PRICE'])) {
            $PRICE = (int) $arXmlData['PRICE'];
        }
        if (isset($arXmlData['CURRENCY'])) {
            $CURRENCY = $arXmlData['CURRENCY'];
        }
        if (isset($arXmlData['TYPE'])) {
            $type = $arXmlData['TYPE'];
        }
        if ('none' !== $type && !in_array($type, $arTypesConfigKeys, true)) {
            $type = 'none';
        }
        if (isset($arXmlData['XML_DATA'])) {
            foreach ($arXmlData['XML_DATA'] as $key => $value) {
                if ('PARAMS' === $key) {
                    $params[$key] = $value;
                } else {
                    $arTypeValues[$type][$key] = $value;
                }
            }
        }
        if (is_array($params['PARAMS']) && !empty($params['PARAMS'])) {
            foreach ($params['PARAMS'] as $strParam) {
                $arAddParams[] = [
                    'PARAM' => $strParam,
                ];
            }
        }
        if (!empty($arXmlData['SKU_EXPORT'])) {
            if (!empty($arXmlData['SKU_EXPORT']['SKU_URL_TEMPLATE_TYPE'])) {
                $arSKUExport['SKU_URL_TEMPLATE_TYPE'] = $arXmlData['SKU_EXPORT']['SKU_URL_TEMPLATE_TYPE'];
            }
            if (!empty($arXmlData['SKU_EXPORT']['SKU_URL_TEMPLATE'])) {
                $arSKUExport['SKU_URL_TEMPLATE'] = $arXmlData['SKU_EXPORT']['SKU_URL_TEMPLATE'];
            }
            if (!empty($arXmlData['SKU_EXPORT']['SKU_EXPORT_COND'])) {
                $arSKUExport['SKU_EXPORT_COND'] = $arXmlData['SKU_EXPORT']['SKU_EXPORT_COND'];
            }
            if (!empty($arXmlData['SKU_EXPORT']['SKU_PROP_COND'])) {
                $arSKUExport['SKU_PROP_COND'] = $arXmlData['SKU_EXPORT']['SKU_PROP_COND'];
            }
        }
        ?>
		<script type="text/javascript">
		var currentSelectedType = '<?php echo $type; ?>';

		function switchType(type)
		{
			BX('config_' + currentSelectedType).style.display = 'none';
			currentSelectedType = type;
			BX('config_' + currentSelectedType).style.display = 'block';
		}
		</script>
		<form name="yandex_form" method="POST">
			<input type="hidden" name="Update" value="Y" />
			<input type="hidden" name="IBLOCK_ID" value="<?php echo $intIBlockID; ?>" />
			<?php echo bitrix_sessid_post(); ?>
<?php
        $tabControl->Begin();
        $tabControl->BeginNextTab();
        ?>
		<tr class="heading">
			<td colspan="2"><?php echo GetMessage('YANDEX_TYPE'); ?></td>
		</tr>
		<tr>
			<td colspan="2" style="text-align: center;">
				<select name="type" onchange="switchType(this[this.selectedIndex].value)">
<?php
        // foreach ($arTypesConfig as $key => $arConfig):
                foreach ($arTypesConfigKeys as $key) {
                    if ('none' !== $key) {
                        ?><option value="<?php echo $key; ?>"<?php echo $type === $key ? ' selected' : ''; ?>><?php echo $key; ?></option><?php
                    } else {
                        ?><option value="none" selected><?php echo GetMessage('YANDEX_TYPE_SIMPLE'); ?></option><?php
                    }
                }
        ?>
				</select>
			</td>
		</tr>
		<tr>
			<td colspan="2" style="text-align: center;">
		<?echo BeginNote(), GetMessage('YANDEX_TYPE_NOTE'), EndNote(); ?>
			</td>
		</tr>
		<tr class="heading">
			<td colspan="2"><?php echo GetMessage('YANDEX_PROPS_TYPE'); ?></td>
		</tr>
		<tr>
			<td colspan="2">
<?php
                foreach ($arTypesConfig as $key => $arConfig) {
                    ?>
				<div id="config_<?php echo htmlspecialcharsbx($key); ?>" style="padding: 10px; display: <?php echo $type === $key ? 'block' : 'none'; ?>;">
					<table width="90%" class="inner" style="text-align: center;">
<?php
                                foreach ($arConfig as $prop) {
                                    ?>
						<tr>
						<td align="right"><?php echo htmlspecialcharsbx(GetMessage('YANDEX_PROP_'.$prop)); ?>: </td>
						<td style="white-space: nowrap;"><?__yand_show_selector($key, $prop, $arIBlock, isset($arTypeValues[$key][$prop]) ? $arTypeValues[$key][$prop] : ''); ?>&nbsp;<small>(<?php echo htmlspecialcharsbx($prop); ?>)</small></td>
						</tr>
<?php
                                }
                    ?>
					</table>
				</div>
<?php
                }
        ?>

			</td>
		</tr>
		<tr class="heading">
			<td colspan="2" valign="top"><?php echo GetMessage('YANDEX_PROPS_ADDITIONAL'); ?></td>
		</tr>
		<tr>
			<td colspan="2">
				<div id="config_param" style="padding: 10px auto; text-align: center;">
				<table class="inner" id="yandex_params_tbl" style="text-align: center; margin: 0 auto;">
					<thead>
					<tr><td style="text-align: center;"> </td>
					<td style="text-align: center;"><?php echo GetMessage('YANDEX_PARAMS_TITLE'); ?></td>
					</tr>
					</thead>
					<tbody>
						<?php
                                $intCount = 0;
        foreach ($arAddParams as $arParamDetail) {
            echo __addParamRow($arIBlock, $intCount, $arParamDetail['PARAM'], '');
            ++$intCount;
        }
        if (0 === $intCount) {
            echo __addParamRow($arIBlock, $intCount, '', '');
            ++$intCount;
        }
        ?>
					</tbody>
				</table>
				<input type="hidden" name="PARAMS_COUNT" id="PARAMS_COUNT" value="<?php echo $intCount; ?>">
				<div style="width: 100%; text-align: center;"><input type="button" onclick="__addYP(); return false;" name="yandex_params_add" value="<?php echo GetMessage('YANDEX_PROPS_ADDITIONAL_MORE'); ?>"></div>
				</div>
<script type="text/javascript">
BX.ready(
	function(){
		setTimeout(function(){
			window.oParamSet = {
				pTypeTbl: BX("yandex_params_tbl"),
				curCount: <?php echo $intCount; ?>,
				intCounter: BX("PARAMS_COUNT")
			};
		},50);
});

function __addYP()
{
	var id = window.oParamSet.curCount++;
	window.oParamSet.intCounter.value = window.oParamSet.curCount;
	var newRow = window.oParamSet.pTypeTbl.insertRow(window.oParamSet.pTypeTbl.rows.length);
	newRow.id = 'yandex_params_tbl_'+id;

	var oCell = newRow.insertCell(-1);
	oCell.style.textAlign = 'center';
	var strContent = '<?php echo CUtil::JSEscape(__addParamCode()); ?>';
	strContent = strContent.replace(/tmp_xxx/ig, id);
	oCell.innerHTML = strContent;
	var oCell = newRow.insertCell(-1);
	var strContent = '<?php echo CUtil::JSEscape(__addParamName($arIBlock, 'tmp_xxx', '')); ?>';
	strContent = strContent.replace(/tmp_xxx/ig, id);
	oCell.innerHTML = strContent;
}
</script>
			</td>
		</tr>
<?php
        if ($boolOffers) {
            ?>
			<tr class="heading">
				<td colspan="2"><?php echo GetMessage('YANDEX_SKU_SETTINGS'); ?></td>
			</tr>
			<tr>
			<td valign="top"><?php echo GetMessage('YANDEX_OFFERS_SELECT'); ?></td><td><?php
                        $arOffersSelect = [
                            0 => '--- '.ToLower(GetMessage('YANDEX_OFFERS_SELECT')).' ---',
                            YANDEX_SKU_EXPORT_ALL => GetMessage('YANDEX_SKU_EXPORT_ALL_TITLE'),
                            YANDEX_SKU_EXPORT_MIN_PRICE => GetMessage('YANDEX_SKU_EXPORT_MIN_PRICE_TITLE'),
                        ];
            if (!empty($arSelectOfferProps)) {
                $arOffersSelect[YANDEX_SKU_EXPORT_PROP] = GetMessage('YANDEX_SKU_EXPORT_PROP_TITLE');
            }
            ?><select name="SKU_EXPORT_COND" id="SKU_EXPORT_COND"><?php
            foreach ($arOffersSelect as $key => $value) {
                ?><option value="<?php echo htmlspecialcharsbx($key); ?>" <?php echo $key === $arSKUExport['SKU_EXPORT_COND'] ? 'selected' : ''; ?>><?php echo htmlspecialcharsEx($value); ?></option><?php
            }
            ?></select><?php
            if (!empty($arSelectOfferProps)) {
                ?><div id="PROP_COND_CONT" style="display: <?php echo YANDEX_SKU_EXPORT_PROP === $arSKUExport['SKU_EXPORT_COND'] ? 'block' : 'none'; ?>;"><?php
                ?><table class="internal"><tbody>
				<tr class="heading">
					<td><?php echo GetMessage('YANDEX_SKU_EXPORT_PROP_ID'); ?></td>
					<td><?php echo GetMessage('YANDEX_SKU_EXPORT_PROP_COND'); ?></td>
					<td><?php echo GetMessage('YANDEX_SKU_EXPORT_PROP_VALUE'); ?></td>
				</tr>
				<tr>
					<td valign="top"><select name="SKU_PROP_COND" id="SKU_PROP_COND">
					<option value="0" <?php echo empty($arSKUExport['SKU_PROP_COND']) ? 'selected' : ''; ?>><?php echo GetMessage('YANDEX_SKU_EXPORT_PROP_EMPTY'); ?></option>
					<?php
                    foreach ($arSelectOfferProps as &$intPropID) {
                        $strSelected = '';
                        if (!empty($arSKUExport['SKU_PROP_COND']['PROP_ID']) && ($intPropID === $arSKUExport['SKU_PROP_COND']['PROP_ID'])) {
                            $strSelected = 'selected';
                        }
                        ?><option value="<?php echo htmlspecialcharsbx($intPropID); ?>" <?php echo $strSelected; ?>><?php echo htmlspecialcharsEx($arIBlock['OFFERS_PROPERTY'][$intPropID]['NAME']); ?></option><?php
                    }
                ?></select></td>
					<td valign="top"><select name="SKU_PROP_SELECT" id="SKU_PROP_SELECT"><option value="">--- <?php echo ToLower(GetMessage('YANDEX_SKU_EXPORT_PROP_COND')); ?> ---</option><?php
                foreach ($arCondSelectProp as $key => $value) {
                    ?><option value="<?php echo htmlspecialcharsbx($key); ?>" <?php echo $key === $arSKUExport['SKU_PROP_COND']['COND'] ? 'selected' : ''; ?>><?php echo htmlspecialcharsEx($value); ?></option><?php
                }
                ?></select></td>
					<td><div id="SKU_PROP_VALUE_DV"><?php
                foreach ($arSelectOfferProps as &$intPropID) {
                    $arProp = $arIBlock['OFFERS_PROPERTY'][$intPropID];
                    ?><div id="SKU_PROP_VALUE_DV_<?php echo $arProp['ID']; ?>" style="display: <?php echo $intPropID === $arSKUExport['SKU_PROP_COND']['PROP_ID'] ? 'block' : 'none'; ?>;"><?php
                    if (!empty($arProp['VALUES'])) {
                        ?><select name="SKU_PROP_VALUE_<?php echo $arProp['ID']; ?>[]" multiple><?php
                        foreach ($arProp['VALUES'] as $intValueID => $strValue) {
                            ?><option value="<?php echo htmlspecialcharsbx($intValueID); ?>" <?php echo !empty($arSKUExport['SKU_PROP_COND']['VALUES']) && in_array($intValueID, $arSKUExport['SKU_PROP_COND']['VALUES'], true) ? 'selected' : ''; ?>><?php echo htmlspecialcharsEx($strValue); ?></option><?php
                        }
                        ?></select><?php
                    } else {
                        if (!empty($arSKUExport['SKU_PROP_COND']['VALUES'])) {
                            foreach ($arSKUExport['SKU_PROP_COND']['VALUES'] as $strValue) {
                                ?><input type="text" name="SKU_PROP_VALUE_<?php echo $arProp['ID']; ?>[]" value="<?php echo htmlspecialcharsbx($strValue); ?>"><br><?php
                            }
                        }
                        ?><input type="text" name="SKU_PROP_VALUE_<?php echo $arProp['ID']; ?>[]" value=""><br>
							<input type="text" name="SKU_PROP_VALUE_<?php echo $arProp['ID']; ?>[]" value=""><br>
							<input type="text" name="SKU_PROP_VALUE_<?php echo $arProp['ID']; ?>[]" value=""><br>
							<input type="text" name="SKU_PROP_VALUE_<?php echo $arProp['ID']; ?>[]" value=""><br>
							<input type="text" name="SKU_PROP_VALUE_<?php echo $arProp['ID']; ?>[]" value=""><br>
							<?php
                    }
                    ?></div><?php
                }
                ?></div></td>
				</tr>
				</tbody></table><?php
                ?><script type="text/javascript">
				var obExportConds = null;
				var obPropCondCont = null;
				var obSelectProps = null;
				var arPropLayers = new Array();
				<?php
                $intCount = 0;
                foreach ($arSelectOfferProps as &$intPropID) {
                    ?> arPropLayers[<?php echo $intCount; ?>] = {'ID': <?php echo $intPropID; ?>, 'OBJ': null};
					<?php
                    ++$intCount;
                }
                ?>

				function changeValueDiv()
				{
					if (obSelectProps)
					{
						var intCurPropID = obSelectProps.options[obSelectProps.selectedIndex].value;
						for (i = 0; i < arPropLayers.length; i++)
							if (arPropLayers[i].OBJ)
								BX.style(arPropLayers[i].OBJ, 'display', (intCurPropID == arPropLayers[i].ID ? 'block' : 'none'));
					}
				}

				function changePropCondCont()
				{
					if (obExportConds && obPropCondCont)
					{
						var intTypeCond = obExportConds.options[obExportConds.selectedIndex].value;
						BX.style(obPropCondCont, 'display', (intTypeCond == <?php echo YANDEX_SKU_EXPORT_PROP; ?> ? 'block' : 'none'));
					}
				}

				BX.ready(function(){
					for (i = 0; i < arPropLayers.length; i++)
					{
						arPropLayers[i].OBJ = BX('SKU_PROP_VALUE_DV_'+arPropLayers[i].ID);
					}

					obSelectProps = BX('SKU_PROP_COND');
					if (obSelectProps)
						BX.bind(obSelectProps, 'change', changeValueDiv);
					obExportConds = BX('SKU_EXPORT_COND');
					obPropCondCont = BX('PROP_COND_CONT');
					if (obExportConds && obPropCondCont)
					{
						BX.bind(obExportConds, 'change', changePropCondCont);
					}
				});
				</script><?php
                ?></div><?php
            }
            ?></td>
			</tr>
<?php
        }

        $tabControl->BeginNextTab();

        $arGroups = '';
        $dbRes = CCatalogGroup::GetGroupsList(['GROUP_ID' => 2]);
        while ($arRes = $dbRes->Fetch()) {
            if ('Y' === $arRes['BUY']) {
                $arGroups[] = $arRes['CATALOG_GROUP_ID'];
            }
        }
        ?>
	<tr class="heading">
		<td colspan="2"><?php echo GetMessage('YANDEX_PRICES'); ?></td>
	</tr>

	<tr>
		<td><?php echo GetMessage('YANDEX_PRICE_TYPE'); ?>: </td>
		<td><br /><select name="PRICE">
			<option value=""<?php echo '' === $PRICE || 0 === $PRICE ? ' selected' : ''; ?>><?php echo GetMessage('YANDEX_PRICE_TYPE_NONE'); ?></option>
<?php
            $dbRes = CCatalogGroup::GetListEx(
                ['SORT' => 'ASC'],
                ['ID' => $arGroups],
                false,
                false,
                ['ID', 'NAME', 'BASE']
            );
        while ($arRes = $dbRes->Fetch()) {
            ?><option value="<?php echo $arRes['ID']; ?>"<?php echo $PRICE === $arRes['ID'] ? ' selected' : ''; ?>><?php echo '['.$arRes['ID'].'] '.htmlspecialcharsEx($arRes['NAME']); ?></option><?php
        }
        ?>
		</select><br /><br /></td>
	</tr>
	<tr class="heading">
		<td colspan="2"><?php echo GetMessage('YANDEX_CURRENCIES'); ?></td>
	</tr>

	<tr>
		<td colspan="2"><br />
<?php
            $arCurrencyList = [];
        $arCurrencyAllowed = ['RUR', 'RUB', 'USD', 'EUR', 'UAH', 'BYR', 'BYN', 'KZT'];
        $dbRes = CCurrency::GetList($by = 'sort', $order = 'asc');
        while ($arRes = $dbRes->GetNext()) {
            if (in_array($arRes['CURRENCY'], $arCurrencyAllowed, true)) {
                $arCurrencyList[$arRes['CURRENCY']] = $arRes['FULL_NAME'];
            }
        }

        $arValues = [
            'SITE' => GetMessage('YANDEX_CURRENCY_RATE_SITE'),
            'CBRF' => GetMessage('YANDEX_CURRENCY_RATE_CBRF'),
            'NBU' => GetMessage('YANDEX_CURRENCY_RATE_NBU'),
            'NBK' => GetMessage('YANDEX_CURRENCY_RATE_NBK'),
            'CB' => GetMessage('YANDEX_CURRENCY_RATE_CB'),
        ];
        ?>
<table cellpadding="2" cellspacing="0" border="0" class="internal" style="text-align: center;">
<thead>
	<tr class="heading">
		<td colspan="2"><?php echo GetMessage('YANDEX_CURRENCY'); ?></td>
		<td><?php echo GetMessage('YANDEX_CURRENCY_RATE'); ?></td>
		<td><?php echo GetMessage('YANDEX_CURRENCY_PLUS'); ?></td>
	</tr>
</thead>
<tbody>
<?php
            foreach ($arCurrencyList as $strCurrency => $strCurrencyName) {
                ?>
	<tr>
		<td><input type="checkbox" name="CURRENCY[]" id="CURRENCY_<?php echo $strCurrency; ?>" value="<?php echo $strCurrency; ?>"<?php echo empty($CURRENCY) || isset($CURRENCY[$strCurrency]) ? ' checked="checked"' : ''; ?> /></td>
		<td><label for="CURRENCY_<?php echo $strCurrency; ?>" class="text">[<?php echo $strCurrency; ?>] <?php echo $strCurrencyName; ?></label></td>
		<td><select name="CURRENCY_RATE[<?php echo $strCurrency; ?>]" onchange="BX('CURRENCY_PLUS_<?php echo $strCurrency; ?>').disabled = this[this.selectedIndex].value == 'SITE'">
<?php
                        $strRate = 'SITE';
                if (isset($CURRENCY[$strCurrency], $CURRENCY[$strCurrency]['rate'])) {
                    $strRate = $CURRENCY[$strCurrency]['rate'];
                }
                if (!array_key_exists($strRate, $arValues)) {
                    $strRate = 'SITE';
                }
                foreach ($arValues as $key => $title) {
                    ?>
			<option value="<?php echo htmlspecialcharsbx($key); ?>"<?php echo $strRate === $key ? ' selected' : ''; ?>>(<?php echo htmlspecialcharsEx($key); ?>) <?php echo htmlspecialcharsEx($title); ?></option>
<?php
                }
                ?>
		</select></td>
		<?php
                        $strPlus = '';
                if (isset($CURRENCY[$strCurrency], $CURRENCY[$strCurrency]['plus'])) {
                    $strPlus = $CURRENCY[$strCurrency]['plus'];
                }
                ?>
		<td>+<input type="text" size="3" id="CURRENCY_PLUS_<?php echo $strCurrency; ?>" name="CURRENCY_PLUS[<?php echo $strCurrency; ?>]"<?php echo 'SITE' === $strRate ? ' disabled="disabled"' : ''; ?> value="<?php echo htmlspecialcharsbx($strPlus); ?>" />%</td>
	</tr>
<?php
            }
        ?>
</tbody>
</table>

		</td>
	</tr>
<?php
                $tabControl->EndTab();
        $tabControl->Buttons([]);
        $tabControl->End();

        require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';
    }
}
?>