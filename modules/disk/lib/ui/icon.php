<?php

namespace Bitrix\Disk\Ui;

use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\SpecificFolder;
use Bitrix\Disk\TypeFile;

/**
 * Class Icon
 * @package Bitrix\Disk\Ui
 *
 * CSS classes (modules/disk/install/js/disk/css/disk.css)
 */
final class Icon
{
	protected static array $possibleIconClasses = [
		'pdf' => 'icon-pdf',
		'doc' => 'icon-doc',
		'flp' => 'icon-board',
		'board' => 'icon-board',
		'docx' => 'icon-doc',
		'ppt' => 'icon-ppt',
		'pptx' => 'icon-ppt',
		'xls' => 'icon-xls',
		'xlsx' => 'icon-xls',
		'php' => 'icon-php',
		'txt' => 'icon-txt',
		'zip' => 'icon-zip',
		'rar' => 'icon-rar',
		'emp' => 'icon-emp',
		'img' => 'icon-img',
		'exe' => 'icon-exe',
		'vid' => 'icon-vid',
		'odf' => 'icon-odf',
		'odt' => 'icon-odt',
		'ods' => 'icon-ods',
		'odp' => 'icon-odp',
		'non' => 'icon-non',
	];
	private static array $specificFolderIcons = [
		SpecificFolder::CODE_FOR_MAIL_ATTACHMENTS => 'icon-mail',
	];
	private static array $iconSetNames = [
		'pdf' => 'pdf',
		'doc' => 'doc',
		'docx' => 'docx',
		'flp' => 'board',
		'board' => 'board',
		'ppt' => 'ppt',
		'pptx' => 'pptx',
		'xls' => 'xls',
		'xlsx' => 'xlsx',
		'php' => 'php',
		'txt' => 'txt',
		'zip' => 'zip',
		'rar' => 'rar',
		'psd' => 'psd',
		'odf' => 'odf',
		'odt' => 'odt',
		'ods' => 'ods',
		'odp' => 'odp',
	];

	public static function getIconClassByObject(BaseObject $object, $appendSharedClass = false): string
	{
		$class = '';
		if($object instanceof Folder)
		{
			$class = 'bx-disk-folder-icon';

			$specificIcon = self::$specificFolderIcons[$object->getCode()] ?? null;
			if($specificIcon !== null)
			{
				$class .= " $specificIcon";
				if($appendSharedClass)
				{
					$class .= '-shared';
				}

				return $class;
			}
		}
		elseif($object instanceof File)
		{
			$class = 'bx-disk-file-icon';
			$ext = mb_strtolower($object->getExtension());
			if(isset(self::$possibleIconClasses[$ext]))
			{
				$class .= ' ' . self::$possibleIconClasses[$ext];
			}
			elseif(TypeFile::isImage($object))
			{
				$class .= ' ' . self::$possibleIconClasses['img'];
			}
			elseif(TypeFile::isVideo($object))
			{
				$class .= ' ' . self::$possibleIconClasses['vid'];
			}

		}
		if($object->isLink())
		{
			$class .= ' icon-shared shared icon-shared_2';
		}
		elseif($appendSharedClass)
		{
			$class .= ' icon-shared shared icon-shared_1 icon-shared_2';
		}

		return $class;
	}

	/**
	 * Name of the object type icon in the ui.icon-set.disk set.
	 */
	public static function getIconSetNameByObject(BaseObject $object): string
	{
		if($object instanceof Folder)
		{
			return 'folder';
		}

		if($object instanceof File)
		{
			return self::getIconSetNameByFile($object);
		}

		return 'empty';
	}

	/**
	 * Name of the file type icon in the ui.icon-set.disk set: the markup of such an icon is
	 * <div class="ui-icon-set --{name} --fixed-color"></div>.
	 */
	public static function getIconSetNameByFile(File $file): string
	{
		$extension = mb_strtolower($file->getExtension());
		if(isset(self::$iconSetNames[$extension]))
		{
			return self::$iconSetNames[$extension];
		}

		return match(true)
		{
			TypeFile::isImage($file) => 'image',
			TypeFile::isVideo($file) => 'video',
			TypeFile::isAudio($file) => 'audio',
			TypeFile::isArchive($file) => 'archive',
			TypeFile::isScript($file) => 'scripts',
			default => 'empty',
		};
	}
}
