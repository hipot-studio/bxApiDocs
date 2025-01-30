<?php
IncludeModuleLangFile(__FILE__);
IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'].BX_ROOT.'/modules/main/options.php');
CModule::IncludeModule('calendar');
CModule::IncludeModule('iblock');

if (!$USER->CanDoOperation('edit_php')) { // Is admin
    $APPLICATION->AuthForm(GetMessage('ACCESS_DENIED'));
}

$aTabs = [
    [
        'DIV' => 'edit1', 'TAB' => GetMessage('CAL_OPT_SETTINGS'), 'ICON' => 'calendar_settings', 'TITLE' => GetMessage('CAL_SETTINGS_TITLE'),
    ],
    [
        'DIV' => 'edit2', 'TAB' => GetMessage('CAL_OPT_TYPES'), 'ICON' => 'calendar_settings', 'TITLE' => GetMessage('CAL_OPT_TYPES'),
    ],
];

$tabControl = new CAdminTabControl('tabControl', $aTabs);

CUtil::InitJSCore(['ajax', 'window', 'popup', 'access']);

$arTypes = CCalendarType::GetList();
$dbSites = CSite::GetList($by = 'sort', $order = 'asc', ['ACTIVE' => 'Y']);
$arSites = [];
$default_site = '';
while ($arRes = $dbSites->GetNext()) {
    $arSites[$arRes['ID']] = '('.$arRes['ID'].') '.$arRes['NAME'];
    if ('Y' === $arRes['DEF']) {
        $default_site = $arRes['ID'];
    }
}

$bShowPathForSites = true;
if (count($arSites) <= 1) {
    $bShowPathForSites = false;
}

$arForums = [];
if (CModule::IncludeModule('forum')) {
    $db = CForumNew::GetListEx();
    while ($ar = $db->GetNext()) {
        $arForums[$ar['ID']] = '['.$ar['ID'].'] '.$ar['NAME'];
    }
}

if ('POST' === $REQUEST_METHOD && isset($_REQUEST['save_type']) && 'Y' === $_REQUEST['save_type'] && check_bitrix_sessid()) {
    // CUtil::JSPostUnEscape();
    $APPLICATION->RestartBuffer();
    if (isset($_REQUEST['del_type']) && 'Y' === $_REQUEST['del_type']) {
        $xmlId = trim($_REQUEST['type_xml_id']);
        if ('' !== $xmlId) {
            CCalendarType::Delete($xmlId);
        }
    } else {
        $bNew = isset($_POST['type_new']) && 'Y' === $_POST['type_new'];
        $xmlId = trim($bNew ? $_POST['type_xml_id'] : $_POST['type_xml_id_hidden']);
        $name = trim($_POST['type_name']);

        if ('' !== $xmlId && '' !== $name) {
            $XML_ID = CCalendarType::Edit([
                'NEW' => $bNew,
                'arFields' => [
                    'XML_ID' => $xmlId,
                    'NAME' => $name,
                    'DESCRIPTION' => trim($_POST['type_desc']),
                ],
            ]);

            if ($XML_ID) {
                $arTypes_ = CCalendarType::GetList(['arFilter' => ['XML_ID' => $XML_ID]]);
                if ($arTypes_[0]) {
                    OutputTypeHtml($arTypes_[0]);
                }
            }
        }
    }

    exit;
}

if ('POST' === $REQUEST_METHOD && ($Update.$Apply.$RestoreDefaults) !== '' && check_bitrix_sessid()) {
    if ('' !== $RestoreDefaults) {
        COption::RemoveOption('calendar');
    } else {
        // Save permissions for calendar types
        foreach ($_POST['cal_type_perm'] as $xml_id => $perm) {
            // Save type permissions
            CCalendarType::Edit([
                'NEW' => false,
                'arFields' => [
                    'XML_ID' => $xml_id,
                    'ACCESS' => $perm,
                ],
            ]);
        }

        $SET = [
            'work_time_start' => $_REQUEST['work_time_start'],
            'work_time_end' => $_REQUEST['work_time_end'],
            'year_holidays' => $_REQUEST['year_holidays'],
            'year_workdays' => $_REQUEST['year_workdays'],
            'week_holidays' => implode('|', $_REQUEST['week_holidays']),
            // 'week_start' => $_REQUEST['week_start'],
            'user_name_template' => $_REQUEST['user_name_template'],
            'user_show_login' => isset($_REQUEST['user_show_login']),
            'path_to_user' => $_REQUEST['path_to_user'],
            'path_to_user_calendar' => $_REQUEST['path_to_user_calendar'],
            'path_to_group' => $_REQUEST['path_to_group'],
            'path_to_group_calendar' => $_REQUEST['path_to_group_calendar'],
            'path_to_vr' => $_REQUEST['path_to_vr'],
            'path_to_rm' => $_REQUEST['path_to_rm'],
            'rm_iblock_type' => $_REQUEST['rm_iblock_type'],
            'rm_iblock_id' => $_REQUEST['rm_iblock_id'],
            'denied_superpose_types' => [],
            'pathes_for_sites' => isset($_REQUEST['pathes_for_sites']),
            'pathes' => $_REQUEST['pathes'],
            'dep_manager_sub' => isset($_REQUEST['dep_manager_sub']),
            'forum_id' => (int) $_REQUEST['calendar_forum_id'],
        ];

        foreach ($arTypes as $type) {
            $pathType = 'path_to_type_'.$type['XML_ID'];
            if (isset($_REQUEST[$pathType])) {
                $SET[$pathType] = $_REQUEST[$pathType];
            }
        }

        if (CModule::IncludeModule('video')) {
            $SET['vr_iblock_id'] = $_REQUEST['vr_iblock_id'];
        }

        foreach ($arTypes as $type) {
            if (!in_array($type['XML_ID'], $_REQUEST['denied_superpose_types'], true)) {
                $SET['denied_superpose_types'][] = $type['XML_ID'];
            }
        }

        $CUR_SET = CCalendar::GetSettings(['getDefaultForEmpty' => false]);
        foreach ($CUR_SET as $key => $value) {
            if (!isset($SET[$key]) && isset($value)) {
                $SET[$key] = $value;
            }
        }

        CCalendar::SetSettings($SET);
    }

    if ('' !== $Update && '' !== $_REQUEST['back_url_settings']) {
        LocalRedirect($_REQUEST['back_url_settings']);
    } else {
        LocalRedirect($APPLICATION->GetCurPage().'?mid='.urlencode($mid).'&lang='.urlencode(LANGUAGE_ID).'&back_url_settings='.urlencode($_REQUEST['back_url_settings']).'&'.$tabControl->ActiveTabParam());
    }
}

$dbIBlockType = CIBlockType::GetList();
$arIBTypes = [];
$arIB = [];
while ($arIBType = $dbIBlockType->Fetch()) {
    if ($arIBTypeData = CIBlockType::GetByIDLang($arIBType['ID'], LANG)) {
        $arIB[$arIBType['ID']] = [];
        $arIBTypes[$arIBType['ID']] = '['.$arIBType['ID'].'] '.$arIBTypeData['NAME'];
    }
}

$dbIBlock = CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
while ($arIBlock = $dbIBlock->Fetch()) {
    $arIB[$arIBlock['IBLOCK_TYPE_ID']][$arIBlock['ID']] = ($arIBlock['CODE'] ? '['.$arIBlock['CODE'].'] ' : '').$arIBlock['NAME'];
}

$SET = CCalendar::GetSettings(['getDefaultForEmpty' => false]);

$tabControl->Begin();
?>
<form method="post" name="cal_opt_form" action="<?php echo $APPLICATION->GetCurPage(); ?>?mid=<?php echo urlencode($mid); ?>&amp;lang=<?php echo LANGUAGE_ID; ?>">
<?php echo bitrix_sessid_post(); ?>
<?php
$arDays = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

$arWorTimeList = [];
for ($i = 0; $i < 24; ++$i) {
    $arWorTimeList[(string) $i] = CCalendar::FormatTime($i, 0);
    $arWorTimeList[(string) $i.'.30'] = CCalendar::FormatTime($i, 30);
}
$tabControl->BeginNextTab();
?>
	<tr>
		<td><label for="cal_work_time"><?php echo GetMessage('CAL_WORK_TIME'); ?>:</label></td>
		<td>
			<select id="cal_work_time" name="work_time_start">
				<?foreach ($arWorTimeList as $key => $val) { ?>
					<option value="<?php echo $key; ?>" <?php if ($SET['work_time_start'] === $key) {
					    echo ' selected="selected" ';
					}?>><?php echo $val; ?></option>
				<?}?>
			</select>
			&mdash;
			<select id="cal_work_time" name="work_time_end">
				<?foreach ($arWorTimeList as $key => $val) { ?>
					<option value="<?php echo $key; ?>" <?php if ($SET['work_time_end'] === $key) {
					    echo ' selected="selected" ';
					}?>><?php echo $val; ?></option>
				<?}?>
			</select>
		</td>
	</tr>

	<tr>
		<td style="vertical-align: top;"><label for="cal_week_holidays"><?php echo GetMessage('CAL_WEEK_HOLIDAYS'); ?>:</label></td>
		<td>
			<select size="7" multiple=true id="cal_week_holidays" name="week_holidays[]">
				<?foreach ($arDays as $day) { ?>
					<option value="<?php echo $day; ?>" <?if (in_array($day, $SET['week_holidays'], true)) {
					    echo ' selected="selected"';
					}?>><?php echo GetMessage('CAL_OPTION_FIRSTDAY_'.$day); ?></option>
				<?}?>
			</select>
		</td>
	</tr>
	<?php /*
    <tr>
        <td><label for="cal_week_start"><?= GetMessage("CAL_OPTION_FIRSTDAY")?>:</label></td>
        <td>
            <select id="cal_week_start" name="week_start">
                <?foreach($arDays as $day):?>
                    <option value="<?= $day?>" <? if ($SET['week_start'] == $day){echo ' selected="selected" ';}?>><?= GetMessage('CAL_OPTION_FIRSTDAY_'.$day)?></option>
                <?endforeach;?>
            </select>
        </td>
    </tr>
    */
    ?>
	<tr>
		<td><label for="cal_year_holidays"><?php echo GetMessage('CAL_YEAR_HOLIDAYS'); ?>:</label></td>
		<td>
			<input name="year_holidays" type="text" value="<?php echo htmlspecialcharsbx($SET['year_holidays']); ?>" id="cal_year_holidays" size="60"/>
		</td>
	</tr>
	<tr>
		<td><label for="cal_year_workdays"><?php echo GetMessage('CAL_YEAR_WORKDAYS'); ?>:</label></td>
		<td>
			<input name="year_workdays" type="text" value="<?php echo htmlspecialcharsbx($SET['year_workdays']); ?>" id="cal_year_workdays" size="60"/>
		</td>
	</tr>
	<?if (CCalendar::IsIntranetEnabled()) { ?>
	<tr>
		<td><label for="cal_user_name_template"><?php echo GetMessage('CAL_USER_NAME_TEMPLATE'); ?>:</label></td>
		<td>
			<input name="user_name_template" type="text" value="<?php echo htmlspecialcharsbx($SET['user_name_template']); ?>" id="cal_user_name_template" size="60" />
		</td>
	</tr>
	<tr>
		<td><input name="user_show_login" type="checkbox" value="Y" id="cal_user_show_login" <?if ($SET['user_show_login']) {
		    echo 'checked';
		}?>/></td>
		<td>
			<label for="cal_user_show_login"><?php echo GetMessage('CAL_USER_SHOW_LOGIN'); ?></label>
		</td>
	</tr>
	<tr title="<?php echo GetMessage('CAL_DEP_MANAGER_SUB_TITLE'); ?>">
		<td><input name="dep_manager_sub" type="checkbox" value="Y" id="cal_dep_manager_sub" <?if ($SET['dep_manager_sub']) {
		    echo 'checked';
		}?>/></td>
		<td>
			<label for="cal_dep_manager_sub"><?php echo GetMessage('CAL_DEP_MANAGER_SUB'); ?></label>
		</td>
	</tr>
	<tr>
		<td style="vertical-align: top;"><label for="denied_superpose_types"><?php echo GetMessage('CAL_SP_TYPES'); ?>:</label></td>
		<td>
			<select size="3" multiple=true id="denied_superpose_types" name="denied_superpose_types[]">
				<?foreach ($arTypes as $type) { ?>
					<option value="<?php echo $type['XML_ID']; ?>" <?if (!in_array($type['XML_ID'], $SET['denied_superpose_types'], true)) {
					    echo ' selected="selected"';
					}?>><?php echo htmlspecialcharsex($type['NAME']); ?></option>
				<?}?>
			</select>
		</td>
	</tr>

	<!-- Path parameters title -->
	<tr class="heading"><td colSpan="2"><?php echo GetMessage('CAL_PATH_TITLE'); ?></td></tr>

	<?php
    $arPathes = CCalendar::GetPathesList();
	    $commonForSites = $SET['pathes_for_sites'];
	    if (count($arSites) > 1) { ?>
	<tr>
		<td>
		<input name="pathes_for_sites" type="checkbox"  id="cal_pathes_for_sites" <?if ($commonForSites) {
		    echo 'checked=true';
		}?> value="Y" /></td>
		<td>
			<label for="cal_pathes_for_sites"><?php echo GetMessage('CAL_PATH_COMMON'); ?></label>
<script>
BX.ready(function(){
	BX('cal_pathes_for_sites').onclick = function()
	{
		BX('bx-cal-opt-sites-pathes-tr').style.display = this.checked ? 'none' : '';
		<?foreach ($arPathes as $pathName) { ?>
			BX('bx-cal-opt-path-<?php echo $pathName; ?>').style.display = this.checked ? '' : 'none';
		<?}?>
	};
});
</script>
		</td>
	</tr>
	<tr id="bx-cal-opt-sites-pathes-tr" <?if ($commonForSites) {
	    echo 'style="display:none;"';
	}?>>
		<td colSpan="2" align="center">
		<?php
        $aSubTabs = [];
	        foreach ($arSites as $siteId => $siteName) {
	            $aSubTabs[] = ['DIV' => 'opt_cal_path_'.$siteId, 'TAB' => $siteName, 'TITLE' => $siteName];
	        }

	        $arChildTabControlUserCommon = new CAdminViewTabControl('childTabControlUserCommon', $aSubTabs);
	        $arChildTabControlUserCommon->Begin(); ?>
		<?foreach ($arSites as $siteId => $siteName) { ?>
		<?$arChildTabControlUserCommon->BeginNextTab(); ?>
			<table>
			<?php
	            foreach ($arPathes as $pathName) {
	                $val = $SET['pathes'][$siteId][$pathName];
	                if (!isset($val) || empty($val)) {
	                    $val = $SET[$pathName];
	                }

	                $title = GetMessage('CAL_'.strtoupper($pathName));
	                if ('' === $title && 'path_to_type_' === substr($pathName, 0, strlen('path_to_type_'))) {
	                    $typeXmlId = substr($pathName, strlen('path_to_type_'));
	                    foreach ($arTypes as $type) {
	                        if ($type['XML_ID'] === $typeXmlId) {
	                            $title = GetMessage('CAL_PATH_TO_CAL_TYPE', ['#CALENDAR_TYPE#' => $type['NAME']]);

	                            break;
	                        }
	                    }
	                }
	                ?>
				<tr>
					<td class="field-name"><label for="cal_<?php echo $pathName; ?>"><?php echo $title; ?>:</label></td>
					<td>
						<input name="pathes[<?php echo $siteId; ?>][<?php echo $pathName; ?>]" type="text" value="<?php echo htmlspecialcharsbx($val); ?>" id="cal_<?php echo $pathName; ?>" size="60"/>
					</td>
				</tr>
			<?}?>
			</table>
		<?}?>
		<?$arChildTabControlUserCommon->End(); ?>
		</td>
	</tr>
	<?} /* if (count($arSites) > 1) */ ?>

	<?php
    // common pathes for all sites
    if (count($arSites) <= 1) {
        $commonForSites = true;
    }

	    foreach ($arPathes as $pathName) {
	        $title = GetMessage('CAL_'.strtoupper($pathName));
	        if ('' === $title && 'path_to_type_' === substr($pathName, 0, strlen('path_to_type_'))) {
	            $typeXmlId = substr($pathName, strlen('path_to_type_'));
	            foreach ($arTypes as $type) {
	                if ($type['XML_ID'] === $typeXmlId) {
	                    $title = GetMessage('CAL_PATH_TO_CAL_TYPE', ['#CALENDAR_TYPE#' => $type['NAME']]);

	                    break;
	                }
	            }
	        }

	        ?>
	<tr id="bx-cal-opt-path-<?php echo $pathName; ?>"  <?if (!$commonForSites) {
	    echo 'style="display:none;"';
	}?>>
		<td><label for="cal_<?php echo $pathName; ?>"><?php echo $title; ?>:</label></td>
		<td>
			<input name="<?php echo $pathName; ?>" type="text" value="<?php echo htmlspecialcharsbx($SET[$pathName]); ?>" id="cal_<?php echo $pathName; ?>" size="60"/>
		</td>
	</tr>
	<?}?>

	<!-- Reserve meetings and video reserve meetings -->
	<tr class="heading"><td colSpan="2"><?php echo GetMessage('CAL_RESERVE_MEETING'); ?></td></tr>
	<tr>
		<td><label for="cal_rm_iblock_type"><?php echo GetMessage('CAL_RM_IBLOCK_TYPE'); ?>:</label></td>
		<td>
			<select name="rm_iblock_type" onchange="changeIblockList(this.value)">
				<option value=""><?php echo GetMessage('CAL_NOT_SET'); ?></option>
			<?foreach ($arIBTypes as $ibtype_id => $ibtype_name) { ?>
				<option value="<?php echo $ibtype_id; ?>" <?if ($ibtype_id === $SET['rm_iblock_type']) {
				    echo ' selected="selected"';
				}?>><?php echo $ibtype_name; ?></option>
			<?}?>
			</select>
		</td>
	</tr>
	<tr>
		<td><label for="cal_rm_iblock_id"><?php echo GetMessage('CAL_RM_IBLOCK_ID'); ?>:</label></td>
		<td>
			<select id="cal_rm_iblock_id" name="rm_iblock_id">
<?if ($SET['rm_iblock_type']) { ?>
	<option value=""><?php echo GetMessage('CAL_NOT_SET'); ?></option>
	<?foreach ($arIB[$SET['rm_iblock_type']] as $iblock_id => $iblock) { ?>
		<option value="<?php echo $iblock_id; ?>"<?php if ($iblock_id === $SET['rm_iblock_id']) {
		    echo ' selected="selected"';
		}?>><?php echo $iblock; ?></option>
	<?}?>
<?} else { ?>
	<option value=""><?php echo GetMessage('CAL_NOT_SET'); ?></option>
<?}?>

			</select>
		</td>
	</tr>

	<?if (CModule::IncludeModule('video')) { ?>
	<tr>
		<td><label for="cal_vr_iblock_id"><?php echo GetMessage('CAL_VR_IBLOCK_ID'); ?>:</label></td>
		<td>
			<select id="cal_vr_iblock_id" name="vr_iblock_id"">
<?if ($SET['rm_iblock_type']) { ?>
	<option value=""><?php echo GetMessage('CAL_NOT_SET'); ?></option>
	<?foreach ($arIB[$SET['rm_iblock_type']] as $iblock_id => $iblock) { ?>
		<option value="<?php echo $iblock_id; ?>"<?php if ($iblock_id === $SET['vr_iblock_id']) {
		    echo ' selected="selected"';
		}?>><?php echo $iblock; ?></option>
	<?}?>
<?} else { ?>
	<option value=""><?php echo GetMessage('CAL_NOT_SET'); ?></option>
<?}?>
			</select>
		</td>
	</tr>
	<?}?>
	<?}?>


	<!-- Comments settings -->
	<tr class="heading"><td colSpan="2"><?php echo GetMessage('CAL_COMMENTS_SETTINGS'); ?></td></tr>
	<tr>
		<td align="right"><?php echo GetMessage('CAL_COMMENTS_FORUM'); ?>:</td>
		<td>
			<select name="calendar_forum_id">
				<option value="0">&nbsp;</option>
				<?foreach ($arForums as $key => $value) { ?>
					<option value="<?php echo $key; ?>"<?php echo $SET['forum_id'] === $key ? ' selected' : ''; ?>><?php echo $value; ?></option>
				<?php }?>
			</select>
		</td>
	</tr>
<?/*
    <tr>
        <td align="right"><?= GetMessage("CAL_COMMENTS_ALLOW_EDIT")?>:</td>
        <td>
            <input type="checkbox" name="calendar_comment_allow_edit" value="Y"<?= $SET['comment_allow_edit'] ? " checked" : "" ?> />
        </td>
    </tr>
    <tr>
        <td align="right"><?= GetMessage("CAL_COMMENTS_ALLOW_REMOVE")?>:</td>
        <td>
            <input type="checkbox" name="calendar_comment_allow_remove" value="Y"<?= $SET['comment_allow_remove'] ? " checked" : "" ?> />
        </td>
    </tr>
    <tr>
        <td align="right"><?= GetMessage('CAL_MAX_UPLOAD_FILES_IN_COMMENTS')?>:</td>
        <td><input type="text" size="40" value="<?= $SET['max_upload_files_in_comments']?>" name="calendar_max_upload_files_in_comments">
        </td>
    </tr>
*/ ?>
	<!-- END Comments settings -->



<?$tabControl->BeginNextTab(); ?>
	<tr class="">
		<td colspan="2" style="text-align: left;">
			<a class="bxco-add-type" href="javascript:void(0);" onclick="addType(); return false;" title="<?php echo GetMessage('CAL_ADD_TYPE_TITLE'); ?>"><i></i><span><?php echo GetMessage('CAL_ADD_TYPE'); ?></span></a>
		</td>
	</tr>
	<tr><td colspan="2" align="center">
<?php
$APPLICATION->SetAdditionalCSS('/bitrix/js/calendar/cal-style.css');
$GLOBALS['APPLICATION']->AddHeadScript('/bitrix/js/calendar/cal-controlls.js');
?>
	<table id="bxcal_type_tbl" style="width: 650px;">
		<?php
        $actionUrl = '/bitrix/admin/settings.php?mid=calendar&lang='.LANG;
$arXML_ID = [];
for ($i = 0, $l = count($arTypes); $i < $l; ++$i) {
    $type = $arTypes[$i];
    $arXML_ID[$type['XML_ID']] = true;
    ?>
			<tr><td>
			<?php echo OutputTypeHtml($type); ?>
			</td></tr>
		<?}?>
	</table>
	</td></tr>

<?$tabControl->BeginNextTab(); ?>

<?$tabControl->Buttons(); ?>
	<input type="submit" class="adm-btn-save" name="Update" value="<?php echo GetMessage('MAIN_SAVE'); ?>" title="<?php echo GetMessage('MAIN_OPT_SAVE_TITLE'); ?>" />
	<input type="submit" name="Apply" value="<?php echo GetMessage('MAIN_APPLY'); ?>" title="<?php echo GetMessage('MAIN_OPT_APPLY_TITLE'); ?>">
	<?if ('' !== $_REQUEST['back_url_settings']) { ?>
		<input type="button" name="Cancel" value="<?php echo GetMessage('MAIN_OPT_CANCEL'); ?>" title="<?php echo GetMessage('MAIN_OPT_CANCEL_TITLE'); ?>" onclick="window.location='<?php echo htmlspecialcharsbx(CUtil::addslashes($_REQUEST['back_url_settings'])); ?>'">
		<input type="hidden" name="back_url_settings" value="<?php echo htmlspecialcharsbx($_REQUEST['back_url_settings']); ?>">
	<?}?>
	<input type="submit" name="RestoreDefaults" title="<?echo GetMessage('MAIN_HINT_RESTORE_DEFAULTS'); ?>" onclick="return confirm('<?echo addslashes(GetMessage('MAIN_HINT_RESTORE_DEFAULTS_WARNING')); ?>')" value="<?echo GetMessage('CAL_RESTORE_DEFAULTS'); ?>">
<?$tabControl->End(); ?>
</form>

<div id="edit_type_dialog" class="bxco-popup">
<form method="POST" name="caltype_dialog_form" id="caltype_dialog_form" action="<?php echo $APPLICATION->GetCurPage(); ?>?mid=<?php echo urlencode($mid); ?>&amp;lang=<?php echo LANGUAGE_ID; ?>&amp;save_type=Y"  ENCTYPE="multipart/form-data">
	<?php echo bitrix_sessid_post(); ?>
	<input type="hidden"  name="type_new" id="type_new_inp" value="Y" size="32" />
	<table border="0" cellSpacing="0" class="bxco-popup-tbl">
		<tr>
			<td class="bxco-2-right">
				<label for="type_name_inp"><b><?php echo GetMessage('CAL_TYPE_NAME'); ?></b>:</label>
			</td>
			<td><input type="text"  name="type_name" id="type_name_inp" value="" size="32" /></td>
		</tr>
		<tr>
			<td class="bxco-2-right">
				<label for="type_xml_id_inp"><b><?php echo GetMessage('CAL_TYPE_XML_ID'); ?></b>:</label>
				<br>
				<span class="bxco-lbl-note"><?php echo GetMessage('CAL_ONLY_LATIN'); ?></span>
			</td>
			<td>
				<input type="hidden"  name="type_xml_id_hidden" id="type_xml_id_hidden_inp" value="" size="32" />
				<input type="text"  name="type_xml_id" id="type_xml_id_inp" value="" size="32" />
			</td>
		</tr>
		<tr>
			<td class="bxco-2-right"><label for="type_desc_inp"><?php echo GetMessage('CAL_TYPE_DESCRIPTION'); ?>:</label></td>
			<td><textarea name="type_desc" id="type_desc_inp" rows="3" cols="30" style="resize:none;"></textarea></td>
		</tr>
	</table>
</form>
</div>

<script>

var arIblocks = <?php echo CUtil::PhpToJsObject($arIB); ?>;
function changeIblockList(value, index)
{
	if (null == index)
		index = 0;

	var
		i, j,
		arControls = [
			BX('cal_rm_iblock_id'),
			BX('cal_vr_iblock_id')
		];

	for (i = 0; i < arControls.length; i++)
	{
		if (arControls[i])
			arControls[i].options.length = 0;

		arControls[i].options[0] = new Option('<?php echo GetMessage('CAL_NOT_SET'); ?>', '');

		for (j in arIblocks[value])
			arControls[i].options[arControls[i].options.length] = new Option(arIblocks[value][j], j);
	}
}

function addType(oType)
{
	if (!window.BXCEditType)
	{
		window.arXML_ID = <?php echo CUtil::PhpToJsObject($arXML_ID); ?>;
		window.BXCEditType = new BX.PopupWindow("BXCEditType", null, {
			autoHide: true,
			zIndex: 0,
			offsetLeft: 0,
			offsetTop: 0,
			draggable: true,
			bindOnResize: false,
			closeByEsc : true,
			titleBar: '<?php echo GetMessage('CAL_EDIT_TYPE_DIALOG'); ?>',
			closeIcon: { right : "12px", top : "10px"},
			className: 'bxc-popup-window',
			buttons: [
				new BX.PopupWindowButton({
					text: '<?php echo GetMessage('MAIN_SAVE'); ?>',
					className: "popup-window-button-accept",
					events: {click : function()
					{
						// Check form
						// Check name
						if (BX.util.trim(BX('type_name_inp').value) == '')
						{
							alert('<?php echo GetMessage('CAL_TYPE_NAME_WARN'); ?>');
							BX.focus(BX('type_xml_id_inp'));
							return;
						}

						// Check xml_id
						var bNew = BX('type_new_inp').value == 'Y', xmlId;
						if (bNew)
						{
							xmlId = BX.util.trim(BX('type_xml_id_inp').value);
							if (xmlId == '' || window.arXML_ID[xmlId] || xmlId.replace(new RegExp('[^a-z0-9_\-]', 'ig'), "") != xmlId)
							{
								alert('<?php echo GetMessage('CAL_TYPE_XML_ID_WARN'); ?>');
								BX.focus(BX('type_xml_id_inp'));
								return;
							}
						}
						else
						{
							xmlId = BX.util.trim(BX('type_xml_id_hidden_inp').value);
						}

						// Post
						BX.ajax.submit(BX('caltype_dialog_form'), function(result)
						{
							window.arXML_ID[xmlId] = true;
							if (bNew)
							{
								BX('bxcal_type_tbl').insertRow(-1).insertCell(-1).innerHTML = result;
							}
							else
							{
								var pCont = BX('type-cont-' + xmlId);
								if (pCont && pCont.parentNode)
									pCont.parentNode.innerHTML = result;
							}
						});
						window.BXCEditType.close();
					}}
				}),
				new BX.PopupWindowButtonLink({
					text: '<?php echo GetMessage('CAL_CLOSE'); ?>',
					className: "popup-window-button-link-cancel",
					events: {click : function(){window.BXCEditType.close();}}
				})
			],
			content: BX('edit_type_dialog')
		});
	}

	var bNew = !oType;
	BX('type_new_inp').value = bNew ? 'Y' : 'N';
	BX('type_name_inp').value = bNew ? '' : oType.NAME;
	BX('type_desc_inp').value = bNew ? '' : oType.DESCRIPTION;
	BX('type_xml_id_inp').value = bNew ? '' : oType.XML_ID;
	BX('type_xml_id_hidden_inp').value = bNew ? '' : oType.XML_ID;
	BX('type_xml_id_inp').disabled = !bNew;
	window.BXCEditType.show();
}

function delType(xml_id)
{
	if (confirm('<?php echo GetMessage('CAL_DELETE_CONFIRM'); ?>'))
	{
		BX.ajax.post('<?php echo $APPLICATION->GetCurPage(); ?>?mid=<?php echo urlencode($mid); ?>&lang=<?php echo LANGUAGE_ID; ?>&save_type=Y&del_type=Y&type_xml_id=' + xml_id, {sessid: BX.bitrix_sessid()}, function()
		{
			var pCont = BX('type-cont-' + xml_id);
			if (pCont && pCont.parentNode)
				BX.cleanNode(pCont.parentNode, true);
		});
	}
}
</script>

<?php
function OutputTypeHtml($type)
{
    $XML_ID = preg_replace('/[^a-zA-Z0-9_]/i', '', $type['XML_ID']);
    CCalendarSceleton::GetAccessHTML('calendar_type', 'bxec-calendar-type-'.$XML_ID);
    ?>
	<div class="bxcopt-type-cont" id="type-cont-<?php echo $XML_ID; ?>"">
		<div class="bxcopt-type-cont-title">
			<span class="bxcopt-type-title-label"><?php echo htmlspecialcharsbx($type['NAME']); ?> [<?php echo $XML_ID; ?>]</span>
			<a href="javascript:void(0);" onclick="delType('<?php echo $XML_ID; ?>'); return false;"><?php echo GetMessage('CAL_DELETE'); ?></a>
			<a href="javascript:void(0);" onclick="addType(<?php echo CUtil::PhpToJsObject($type); ?>); return false;"><?php echo GetMessage('CAL_CHANGE'); ?></a>
		</div>
		<?php if ('' !== $type['DESCRIPTION']) { ?>
			<span class="bxcopt-type-desc"><?php echo htmlspecialcharsbx($type['DESCRIPTION']); ?></span>
		<?php }?>
		<div class="bxcopt-type-access-cont">
			<span class="bxcopt-type-access-cont-title"><?php echo GetMessage('CAL_TYPE_PERMISSION_ACCESS'); ?>:</span>
			<div class="bxcopt-type-access-values-cont" id="type-access-values-cont<?php echo $XML_ID; ?>"></div>
			<a class="bxcopt-add-access-link" href="javascript:void(0);" id="type-access-link<?php echo $XML_ID; ?>"><?php echo GetMessage('CAL_ADD_ACCESS'); ?></a>
		</div>
<script>
BX = top.BX;
BX.ready(function()
{
	setTimeout(function(){
		top.accessNames = {};
		var code, arNames = <?php echo CUtil::PhpToJsObject(CCalendar::GetAccessNames()); ?>;
		for (code in arNames)
			top.accessNames[code] = arNames[code];

		top.BXCalAccess<?php echo $XML_ID; ?> = new top.ECCalendarAccess({
			bind: 'calendar-type-<?php echo $XML_ID; ?>',
			GetAccessName: function(code){return top.accessNames[code] || code;},
			inputName: 'cal_type_perm[<?php echo $XML_ID; ?>]',
			pCont: BX('type-access-values-cont<?php echo $XML_ID; ?>'),
			pLink: BX('type-access-link<?php echo $XML_ID; ?>'),
			delTitle: '<?php echo GetMessage('CAL_DELETE'); ?>',
			noAccessRights: '<?php echo GetMessage('CAL_NOT_SET'); ?>'
		});
		top.BXCalAccess<?php echo $XML_ID; ?>.SetSelected(<?php echo CUtil::PhpToJsObject($type['ACCESS']); ?>);
	}, 100);
});
</script>
	</div>

<?php
}

?>
