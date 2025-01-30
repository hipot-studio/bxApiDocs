<?php

IncludeModuleLangFile(__FILE__);
$GLOBALS['BLOG_IMAGE'] = [];

class blog_image
{
    // ADD, UPDATE, DELETE
    public static function CheckFields($ACTION, &$arFields, $ID = 0)
    {
        global $APPLICATION;

        if (is_set($arFields, 'FILE_ID')) {
            $arFile = null;
            if (is_array($arFields['FILE_ID'])) {
                if ('' === $arFields['FILE_ID']['name'] && '' === $arFields['FILE_ID']['del']) {
                    unset($arFields['FILE_ID']);
                }

                $arFile = $arFields['FILE_ID'];
            } else {
                $arFields['FILE_ID'] = (int) $arFields['FILE_ID'];
                if ($arFields['FILE_ID'] > 0) {
                    $arFile = CFile::GetFileArray($arFields['FILE_ID']);
                }
            }

            if ($arFile) {
                $res = CFile::CheckImageFile($arFile, 0, 0, 0);
                if ('' !== $res) {
                    $APPLICATION->ThrowException($res, 'ERROR_ATTACH_IMG');

                    return false;
                }
            }

            if (
                'N' !== $arFields['IMAGE_SIZE_CHECK']
                && (int) $arFields['IMAGE_SIZE'] > 0
                && (int) $arFields['IMAGE_SIZE'] > COption::GetOptionString('blog', 'image_max_size', 5_000_000)
            ) {
                $APPLICATION->ThrowException(GetMessage('ERROR_ATTACH_IMG_SIZE', ['#SIZE#' => (float) (COption::GetOptionString('blog', 'image_max_size', 5_000_000) / 1_000_000)]), 'ERROR_ATTACH_IMG_SIZE');

                return false;
            }

            unset($arFields['IMAGE_SIZE_CHECK']);
        }

        return true;
    }

    public static function ImageFixSize($aFile)
    {
        $file = $aFile['tmp_name'];
        preg_match('#/([a-z]+)#is', $aFile['type'], $regs);
        $ext_tmp = $regs[1];

        $sizeX = COption::GetOptionString('blog', 'image_max_width', 600);
        $sizeY = COption::GetOptionString('blog', 'image_max_height', 600);

        switch ($ext_tmp) {
            case 'jpeg':
            case 'pjpeg':
            case 'jpg':
                if (!function_exists('imageJPEG') || !function_exists('imagecreatefromjpeg')) {
                    return false;
                }

                break;

            case 'gif':
                if (!function_exists('imageGIF') || !function_exists('imagecreatefromgif')) {
                    return false;
                }

                break;

            case 'png':
                if (!function_exists('imagePNG') || !function_exists('imagecreatefrompng')) {
                    return false;
                }

                break;
        }

        switch ($ext_tmp) {
            case 'jpeg':
            case 'pjpeg':
            case 'jpg':
                $imageInput = imagecreatefromjpeg($file);
                $ext_tmp = 'jpg';

                break;

            case 'gif':
                $imageInput = imagecreatefromgif($file);

                break;

            case 'png':
                $imageInput = imagecreatefrompng($file);

                break;
        }

        $imgX = imagesx($imageInput);
        $imgY = imagesy($imageInput);

        if ($imgX > $sizeX || $imgY > $sizeY) {
            $newX = $sizeX;
            $newY = $imgY * ($newX / $imgX);

            if ($newY > $sizeY) {
                $newY = $sizeY;
                $newX = $imgX * ($newY / $imgY);
            }

            if (function_exists('imagecreatetruecolor')) {
                $imageOutput = imagecreatetruecolor($newX, $newY);
            } else {
                $imageOutput = imagecreate($newX, $newY);
            }

            if (function_exists('imagecopyresampled')) {
                imagecopyresampled($imageOutput, $imageInput, 0, 0, 0, 0, $newX, $newY, $imgX, $imgY);
            } else {
                imagecopyresized($imageOutput, $imageInput, 0, 0, 0, 0, $newX, $newY, $imgX, $imgY);
            }

            switch ($ext_tmp) {
                case 'jpg':
                    return imagejpeg($imageOutput, $file);

                case 'gif':
                    return imagegif($imageOutput, $file);

                case 'png':
                    return imagepng($imageOutput, $file);
            }
        }

        return true;
    }

    public static function Delete($ID)
    {
        global $DB;

        $ID = (int) $ID;
        unset($GLOBALS['BLOG_IMAGE']['BLOG_IMAGE_CACHE_'.$ID]);
        if ($res = CBlogImage::GetByID($ID)) {
            CFile::Delete($res['FILE_ID']);

            return $DB->Query('DELETE FROM b_blog_image WHERE ID = '.$ID, true);
        }

        return false;
    }

    // *************** SELECT *********************/
    public static function GetByID($ID)
    {
        global $DB;

        $ID = (int) $ID;

        if (isset($GLOBALS['BLOG_IMAGE']['BLOG_IMAGE_CACHE_'.$ID]) && is_array($GLOBALS['BLOG_IMAGE']['BLOG_IMAGE_CACHE_'.$ID]) && is_set($GLOBALS['BLOG_IMAGE']['BLOG_IMAGE_CACHE_'.$ID], 'ID')) {
            return $GLOBALS['BLOG_IMAGE']['BLOG_IMAGE_CACHE_'.$ID];
        }

        $strSql =
            'SELECT G.* '.
            'FROM b_blog_image G '.
            'WHERE G.ID = '.$ID.'';
        $dbResult = $DB->Query($strSql, false, 'File: '.__FILE__.'<br>Line: '.__LINE__);
        if ($arResult = $dbResult->Fetch()) {
            $GLOBALS['BLOG_IMAGE']['BLOG_IMAGE_CACHE_'.$ID] = $arResult;

            return $arResult;
        }

        return false;
    }

    public static function AddImageResizeHandler($arParams)
    {
        AddEventHandler('main', 'main.file.input.upload', [__CLASS__, 'ImageResizeHandler']);
        $bNull = null;
        self::ImageResizeHandler($bNull, $arParams);
    }

    public static function ImageResizeHandler(&$arCustomFile, $arParams = null)
    {
        static $arResizeParams = [];

        if (null !== $arParams) {
            $arResizeParams = $arParams;
        }

        if ((!is_array($arCustomFile)) || !isset($arCustomFile['fileID'])) {
            return false;
        }

        $fileID = $arCustomFile['fileID'];
        $arFile = CFile::GetFileArray($fileID);
        $arCustomFile['content_type'] = $arFile['CONTENT_TYPE'];
        if ($arFile && null === CFile::CheckImageFile($arFile)) {
            $aImgThumb = CFile::ResizeImageGet(
                $fileID,
                ['width' => 90, 'height' => 90],
                BX_RESIZE_IMAGE_EXACT,
                true
            );
            $arCustomFile['img_thumb_src'] = $aImgThumb['src'];

            $aImgSource = CFile::ResizeImageGet(
                $fileID,
                ['width' => $arResizeParams['width'], 'height' => $arResizeParams['height']],
                BX_RESIZE_IMAGE_PROPORTIONAL,
                true
            );
            $arCustomFile['img_source_src'] = $aImgSource['src'];
        }
    }
}
