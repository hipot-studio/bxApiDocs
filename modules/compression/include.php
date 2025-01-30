<?php

use Bitrix\Main\Data\Cache;

class CCompress
{
    public static function OnPageStart()
    {
        ob_start();
        ob_start(); // second buffering envelope for PHP URL rewrite, see http://bugs.php.net/bug.php?id=35933
        ob_implicit_flush(0);
    }

    public static function OnAfterEpilog()
    {
        global $USER;

        $canEditPHP = $USER->CanDoOperation('edit_php');
        $bShowTime = isset($_SESSION['SESS_SHOW_TIME_EXEC']) && ('Y' === $_SESSION['SESS_SHOW_TIME_EXEC']);
        $bShowStat = ($GLOBALS['DB']->ShowSqlStat && ($canEditPHP || 'Y' === $_SESSION['SHOW_SQL_STAT']));
        $bShowCacheStat = (Cache::getShowCacheStat() && ($canEditPHP || 'Y' === $_SESSION['SHOW_CACHE_STAT']));
        $bExcel = isset($_REQUEST['mode']) && 'excel' === $_REQUEST['mode'];
        $ENCODING = self::CheckCanGzip();
        if (0 !== $ENCODING) {
            $level = 4;

            if (isset($_GET['compress'])) {
                if ('Y' === $_GET['compress'] || 'y' === $_GET['compress']) {
                    $_SESSION['SESS_COMPRESS'] = 'Y';
                } elseif ('N' === $_GET['compress'] || 'n' === $_GET['compress']) {
                    unset($_SESSION['SESS_COMPRESS']);
                }
            }

            if (!defined('ADMIN_AJAX_MODE') && !defined('PUBLIC_AJAX_MODE') && !$bExcel) {
                if ($bShowTime || $bShowStat || $bShowCacheStat) {
                    $main_exec_time = round(getmicrotime() - START_EXEC_TIME, 4);

                    include_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/interface/debug_info.php';
                }

                if (isset($_SESSION['SESS_COMPRESS']) && 'Y' === $_SESSION['SESS_COMPRESS']) {
                    include $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/compression/table.php';
                }
            }

            ob_end_flush();
            $Contents = ob_get_contents();
            ob_end_clean();

            if (!defined('BX_SPACES_DISABLED') || BX_SPACES_DISABLED !== true) {
                if ((strpos($GLOBALS['HTTP_USER_AGENT'], 'MSIE 5') > 0 || strpos($GLOBALS['HTTP_USER_AGENT'], 'MSIE 6.0') > 0) && !str_contains($GLOBALS['HTTP_USER_AGENT'], 'Opera')) {
                    $Contents = str_repeat(' ', 2_048)."\r\n".$Contents;
                }
            }

            $Size = function_exists('mb_strlen') ? mb_strlen($Contents, 'latin1') : strlen($Contents);
            $Crc = crc32($Contents);
            $Contents = gzcompress($Contents, $level);
            $Contents = function_exists('mb_substr') ? mb_substr($Contents, 0, -4, 'latin1') : substr($Contents, 0, -4);

            header("Content-Encoding: {$ENCODING}");
            echo "\x1f\x8b\x08\x00\x00\x00\x00\x00";
            echo $Contents;
            echo pack('V', $Crc);
            echo pack('V', $Size);
        } else {
            ob_end_flush();
            ob_end_flush();
            if (($bShowTime || $bShowStat || $bShowCacheStat) && !defined('ADMIN_AJAX_MODE') && !defined('PUBLIC_AJAX_MODE')) {
                $main_exec_time = round(getmicrotime() - START_EXEC_TIME, 4);

                include_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/interface/debug_info.php';
            }
        }
    }

    public static function DisableCompression()
    {
        // define("BX_COMPRESSION_DISABLED", true);
    }

    public static function Disable2048Spaces()
    {
        // define("BX_SPACES_DISABLED", true);
    }

    public static function CheckCanGzip()
    {
        if (!function_exists('gzcompress')) {
            return 0;
        }
        if (defined('BX_COMPRESSION_DISABLED') && BX_COMPRESSION_DISABLED === true) {
            return 0;
        }
        if (headers_sent() || connection_aborted()) {
            return 0;
        }
        if (1 === ini_get('zlib.output_compression')) {
            return 0;
        }
        if ('' === $GLOBALS['HTTP_ACCEPT_ENCODING']) {
            return 0;
        }
        if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'], 'x-gzip')) {
            return 'x-gzip';
        }
        if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
            return 'gzip';
        }

        return 0;
    }
}
