<?php
// define("STOP_STATISTICS", true);
// define("BX_SECURITY_SHOW_MESSAGE", true);
// define('NO_AGENT_CHECK', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';
if (check_bitrix_sessid()) {
    echo "<script type=\"text/javascript\">\n";

    $bNoTree = true;
    $bIBlock = false;
    $IBLOCK_ID = (int) $_REQUEST['IBLOCK_ID'];
    if ($IBLOCK_ID > 0) {
        CModule::IncludeModule('iblock');
        $rsIBlocks = CIBlock::GetByID($IBLOCK_ID);
        if ($arIBlock = $rsIBlocks->Fetch()) {
            $bRightBlock = CIBlockRights::UserHasRightTo($IBLOCK_ID, $IBLOCK_ID, 'iblock_admin_display');
            if ($bRightBlock) {
                echo 'window.parent.Tree=new Array();';
                echo 'window.parent.Tree[0]=new Array();';

                $bIBlock = true;
                $db_section = CIBlockSection::GetList(['LEFT_MARGIN' => 'ASC'], ['IBLOCK_ID' => $IBLOCK_ID]);
                while ($ar_section = $db_section->Fetch()) {
                    $bNoTree = false;
                    if ((int) $ar_section['RIGHT_MARGIN'] - (int) $ar_section['LEFT_MARGIN'] > 1) {
                        ?>window.parent.Tree[<?echo (int) $ar_section['ID']; ?>]=new Array();<?php
                    }
                    ?>window.parent.Tree[<?echo (int) $ar_section['IBLOCK_SECTION_ID']; ?>][<?echo (int) $ar_section['ID']; ?>]=Array('<?echo CUtil::JSEscape(htmlspecialcharsbx($ar_section['NAME'])); ?>', '');<?php
                }
            }
        }
    }
    if ($bNoTree && !$bIBlock) {
        echo 'window.parent.buildNoMenu();';
    } else {
        echo 'window.parent.buildMenu();';
    }

    echo '</script>';
}
?>