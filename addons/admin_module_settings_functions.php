<?php

use Bitrix\Main\SiteTable;

function __AdmSettingsSaveOptions($module_id, $arOptions)
{
    foreach ($arOptions as $arOption) {
        __AdmSettingsSaveOption($module_id, $arOption);
    }
}

function __AdmSettingsSaveOption($module_id, $arOption)
{
    if (!is_array($arOption) || isset($arOption['note'])) {
        return false;
    }

    if ('statictext' === $arOption[3][0] || 'statichtml' === $arOption[3][0]) {
        return false;
    }

    $arControllerOption = CControllerClient::GetInstalledOptions($module_id);

    if (isset($arControllerOption[$arOption[0]])) {
        return false;
    }

    $name = $arOption[0];
    $isChoiceSites = array_key_exists(6, $arOption) && 'Y' === $arOption[6];

    if ($isChoiceSites) {
        if (isset($_REQUEST[$name.'_all']) && '' !== $_REQUEST[$name.'_all']) {
            COption::SetOptionString($module_id, $name, $_REQUEST[$name.'_all'], $arOption[1]);
        } else {
            COption::RemoveOption($module_id, $name);
        }
        $queryObject = SiteTable::getList([
            'select' => ['LID', 'NAME'],
            'filter' => [],
            'order' => ['SORT' => 'ASC'],
        ]);
        while ($site = $queryObject->fetch()) {
            if (isset($_REQUEST[$name.'_'.$site['LID']]) && '' !== $_REQUEST[$name.'_'.$site['LID']]
                && !isset($_REQUEST[$name.'_all'])) {
                $val = $_REQUEST[$name.'_'.$site['LID']] ?? null;

                if ('checkbox' === $arOption[3][0] && 'Y' !== $val) {
                    $val = 'N';
                } elseif ('multiselectbox' === $arOption[3][0] && is_array($val)) {
                    $val = implode(',', $val);
                } elseif (null === $val) {
                    $val = '';
                } elseif (!is_scalar($val)) {
                    continue;
                }
                COption::SetOptionString($module_id, $name, $val, $arOption[1], $site['LID']);
            } else {
                COption::RemoveOption($module_id, $name, $site['LID']);
            }
        }
    } else {
        if (!isset($_REQUEST[$name])) {
            if ('checkbox' !== $arOption[3][0] && 'multiselectbox' !== $arOption[3][0]) {
                return false;
            }
        }

        $val = $_REQUEST[$name] ?? null;

        if ('checkbox' === $arOption[3][0] && 'Y' !== $val) {
            $val = 'N';
        } elseif ('multiselectbox' === $arOption[3][0] && is_array($val)) {
            $val = implode(',', $val);
        } elseif (null === $val) {
            $val = '';
        } elseif (!is_scalar($val)) {
            return false;
        }

        COption::SetOptionString($module_id, $name, $val, $arOption[1]);
    }

    return null;
}

function __AdmSettingsDrawRow($module_id, $Option)
{
    $arControllerOption = CControllerClient::GetInstalledOptions($module_id);
    if (null === $Option) {
        return;
    }

    if (!is_array($Option)) {
        ?>
		<tr class="heading">
			<td colspan="2"><?php echo $Option; ?></td>
		</tr>
	<?php
    } elseif (isset($Option['note'])) {
        ?>
		<tr>
			<td colspan="2" align="center">
				<?echo BeginNote('align="center"'); ?>
				<?php echo $Option['note']; ?>
				<?echo EndNote(); ?>
			</td>
		</tr>
	<?php
    } else {
        $isChoiceSites = array_key_exists(6, $Option) && 'Y' === $Option[6] ? true : false;
        $listSite = [];
        $listSiteValue = [];
        if ('' !== $Option[0]) {
            if ($isChoiceSites) {
                $queryObject = SiteTable::getList([
                    'select' => ['LID', 'NAME'],
                    'filter' => [],
                    'order' => ['SORT' => 'ASC'],
                ]);
                $listSite[''] = GetMessage('MAIN_ADMIN_SITE_DEFAULT_VALUE_SELECT');
                $listSite['all'] = GetMessage('MAIN_ADMIN_SITE_ALL_SELECT');
                while ($site = $queryObject->fetch()) {
                    $listSite[$site['LID']] = $site['NAME'];
                    $val = COption::GetOptionString($module_id, $Option[0], $Option[2], $site['LID'], true);
                    if ($val) {
                        $listSiteValue[$Option[0].'_'.$site['LID']] = $val;
                    }
                }
                $val = '';
                if (empty($listSiteValue)) {
                    $value = COption::GetOptionString($module_id, $Option[0], $Option[2]);
                    if ($value) {
                        $listSiteValue = [$Option[0].'_all' => $value];
                    } else {
                        $listSiteValue[$Option[0]] = '';
                    }
                }
            } else {
                $val = COption::GetOptionString($module_id, $Option[0], $Option[2]);
            }
        } else {
            $val = $Option[2];
        }
        if ($isChoiceSites) { ?>
			<tr>
				<td colspan="2" style="text-align: center!important;">
					<label><?php echo $Option[1]; ?></label>
				</td>
			</tr>
		<?}?>
		<?if ($isChoiceSites) {
		    foreach ($listSiteValue as $fieldName => $fieldValue) { ?>
			<tr>
				<?php
		            $siteValue = str_replace($Option[0].'_', '', $fieldName);
		        renderLable($Option, $listSite, $siteValue);
		        renderInput($Option, $arControllerOption, $fieldName, $fieldValue);
		        ?>
			</tr>
		<?}?>
	<?} else { ?>
		<tr>
			<?php
            renderLable($Option, $listSite);
	    renderInput($Option, $arControllerOption, $Option[0], $val);
	    ?>
		</tr>
	<?}?>
		<?php if ($isChoiceSites) { ?>
		<tr>
			<td width="50%">
				<a href="javascript:void(0)" onclick="addSiteSelector(this)" class="bx-action-href">
					<?php echo GetMessage('MAIN_ADMIN_ADD_SITE_SELECTOR_1'); ?>
				</a>
			</td>
			<td width="50%"></td>
		</tr>
	<?php } ?>
	<?php
    }
}

function __AdmSettingsDrawList($module_id, $arParams)
{
    foreach ($arParams as $Option) {
        __AdmSettingsDrawRow($module_id, $Option);
    }
}

function renderLable($Option, array $listSite, $siteValue = '')
{
    $type = $Option[3];
    $sup_text = array_key_exists(5, $Option) ? $Option[5] : '';
    $isChoiceSites = array_key_exists(6, $Option) && 'Y' === $Option[6] ? true : false;
    ?>
	<?if ($isChoiceSites) { ?>
	<script type="text/javascript">
		function changeSite(el, fieldName)
		{
			var tr = jsUtils.FindParentObject(el, "tr");
			var sel = null, tagNames = ["select", "input", "textarea"];
			for (var i = 0; i < tagNames.length; i++)
			{
				sel = jsUtils.FindChildObject(tr.cells[1], tagNames[i]);
				if (sel)
				{
					sel.name = fieldName+"_"+el.value;
					break;
				}

			}
		}
		function addSiteSelector(a)
		{
			var row = jsUtils.FindParentObject(a, "tr");
			var tbl = row.parentNode;
			var tableRow = tbl.rows[row.rowIndex-1].cloneNode(true);
			tbl.insertBefore(tableRow, row);
			var sel = jsUtils.FindChildObject(tableRow.cells[0], "select");
			sel.name = "";
			sel.selectedIndex = 0;
			sel = jsUtils.FindChildObject(tableRow.cells[1], "select");
			sel.name = "";
			sel.selectedIndex = 0;
		}
	</script>
	<td width="50%">
		<select onchange="changeSite(this, '<?php echo htmlspecialcharsbx($Option[0]); ?>')">
			<?foreach ($listSite as $lid => $siteName) { ?>
				<option <?if ($siteValue === $lid) {
				    echo 'selected';
				}?> value="<?php echo htmlspecialcharsbx($lid); ?>">
					<?php echo htmlspecialcharsbx($siteName); ?>
				</option>
			<?}?>
		</select>
	</td>
<?} else { ?>
	<td<?if ('multiselectbox' === $type[0] || 'textarea' === $type[0] || 'statictext' === $type[0]
        || 'statichtml' === $type[0]) {
	    echo ' class="adm-detail-valign-top"';
	}?> width="50%"><?php
	if ('checkbox' === $type[0]) {
	    echo "<label for='".htmlspecialcharsbx($Option[0])."'>".$Option[1].'</label>';
	} else {
	    echo $Option[1];
	}
    if ('' !== $sup_text) {
        ?><span class="required"><sup><?php echo $sup_text; ?></sup></span><?php
    }
    ?><a name="opt_<?php echo htmlspecialcharsbx($Option[0]); ?>"></a></td>
<?}
}

function renderInput($Option, $arControllerOption, $fieldName, $val)
{
    $type = $Option[3];
    $disabled = array_key_exists(4, $Option) && 'Y' === $Option[4] ? ' disabled' : '';
    ?><td width="50%"><?php
    if ('checkbox' === $type[0]) {
        ?><input type="checkbox" <?if (isset($arControllerOption[$Option[0]])) {
            echo ' disabled title="'.GetMessage('MAIN_ADMIN_SET_CONTROLLER_ALT').'"';
        }?> id="<?echo htmlspecialcharsbx($Option[0]); ?>" name="<?php echo htmlspecialcharsbx($fieldName); ?>" value="Y"<?if ('Y' === $val) {
            echo ' checked';
        }?><?php echo $disabled; ?><?if (isset($type[2]) && '' !== $type[2]) {
            echo ' '.$type[2];
        }?>><?php
    } elseif ('text' === $type[0] || 'password' === $type[0]) {
        ?><input type="<?echo $type[0]; ?>"<?if (isset($arControllerOption[$Option[0]])) {
            echo ' disabled title="'.GetMessage('MAIN_ADMIN_SET_CONTROLLER_ALT').'"';
        }?> size="<?echo $type[1]; ?>" maxlength="255" value="<?echo htmlspecialcharsbx($val); ?>" name="<?php echo htmlspecialcharsbx($fieldName); ?>"<?php echo $disabled; ?><?php echo 'password' === $type[0] || isset($type['noautocomplete']) && $type['noautocomplete'] ? ' autocomplete="new-password"' : ''; ?>><?php
    } elseif ('selectbox' === $type[0]) {
        $arr = $type[1];
        if (!is_array($arr)) {
            $arr = [];
        }
        ?><select name="<?php echo htmlspecialcharsbx($fieldName); ?>" <?if (isset($arControllerOption[$Option[0]])) {
            echo ' disabled title="'.GetMessage('MAIN_ADMIN_SET_CONTROLLER_ALT').'"';
        }?> <?php echo $disabled; ?>><?php
        foreach ($arr as $key => $v) {
            ?><option value="<?echo $key; ?>"<?if ($val === $key) {
                echo ' selected';
            }?>><?echo htmlspecialcharsbx($v); ?></option><?php
        }
        ?></select><?php
    } elseif ('multiselectbox' === $type[0]) {
        $arr = $type[1];
        if (!is_array($arr)) {
            $arr = [];
        }
        $arr_val = explode(',', $val);
        ?><select size="5" <?if (isset($arControllerOption[$Option[0]])) {
            echo ' disabled title="'.GetMessage('MAIN_ADMIN_SET_CONTROLLER_ALT').'"';
        }?> multiple name="<?php echo htmlspecialcharsbx($fieldName); ?>[]"<?php echo $disabled; ?>><?php
        foreach ($arr as $key => $v) {
            ?><option value="<?echo $key; ?>"<?if (in_array($key, $arr_val, true)) {
                echo ' selected';
            }?>><?echo htmlspecialcharsbx($v); ?></option><?php
        }
        ?></select><?php
    } elseif ('textarea' === $type[0]) {
        ?><textarea <?if (isset($arControllerOption[$Option[0]])) {
            echo ' disabled title="'.GetMessage('MAIN_ADMIN_SET_CONTROLLER_ALT').'"';
        }?> rows="<?echo $type[1]; ?>" cols="<?echo $type[2]; ?>" name="<?php echo htmlspecialcharsbx($fieldName); ?>"<?php echo $disabled; ?>><?echo htmlspecialcharsbx($val); ?></textarea><?php
    } elseif ('statictext' === $type[0]) {
        echo htmlspecialcharsbx($val);
    } elseif ('statichtml' === $type[0]) {
        echo $val;
    }?>
	</td><?php
}

?>