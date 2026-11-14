<?php

namespace Bitrix\Disk\Ui;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\FilePicker\Filter;
use Bitrix\Disk\Search\Reindex\ExtendedIndex;
use Bitrix\Disk\User;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\UI\Filter\DateType;
use Bitrix\Main\UI\Filter\Options;

class FolderListFilter
{
	public const FILE_TYPE_FIELD_ID = 'FILE_TYPE_FILTERS';

	private const WITH_EXTERNAL_LINK = 1;
	private const SHARED_FROM_ME = 2;
	private const SHARED_TO_ME = 3;

	/** Preset ids are a part of saved user filter settings, so they are never renamed after the release. */
	private const FILE_TYPE_BY_PRESET_ID = [
		'disk_file_picker_documents' => Filter::TYPE_DOCUMENT,
		'disk_file_picker_spreadsheets' => Filter::TYPE_SPREADSHEET,
		'disk_file_picker_presentations' => Filter::TYPE_PRESENTATION,
		'disk_file_picker_boards' => Filter::TYPE_BOARD,
		'disk_file_picker_images' => Filter::TYPE_IMAGE,
		'disk_file_picker_audio' => Filter::TYPE_AUDIO,
		'disk_file_picker_video' => Filter::TYPE_VIDEO,
		'disk_file_picker_other' => Filter::TYPE_OTHER,
	];

	private const FILE_TYPE_PHRASE_CODES = [
		Filter::TYPE_DOCUMENT => 'DISK_FOLDER_FILTER_FILE_TYPE_DOCUMENT',
		Filter::TYPE_SPREADSHEET => 'DISK_FOLDER_FILTER_FILE_TYPE_SPREADSHEET',
		Filter::TYPE_PRESENTATION => 'DISK_FOLDER_FILTER_FILE_TYPE_PRESENTATION',
		Filter::TYPE_BOARD => 'DISK_FOLDER_FILTER_FILE_TYPE_BOARD',
		Filter::TYPE_IMAGE => 'DISK_FOLDER_FILTER_FILE_TYPE_IMAGE',
		Filter::TYPE_AUDIO => 'DISK_FOLDER_FILTER_FILE_TYPE_AUDIO',
		Filter::TYPE_VIDEO => 'DISK_FOLDER_FILTER_FILE_TYPE_VIDEO',
		Filter::TYPE_OTHER => 'DISK_FOLDER_FILTER_FILE_TYPE_OTHER',
	];

	private int $storageId;
	private bool $trashcanMode;

	public function __construct(int $storageId, bool $trashcanMode = false)
	{
		$this->trashcanMode = $trashcanMode;
		$this->storageId = $storageId;
	}

	public function getId(): string
	{
		$prefix = $this->trashcanMode ? 'trashcan_' : 'folder_list_';

		return $prefix . $this->storageId;
	}

	/**
	 * @throws ObjectException
	 */
	public function getConfig(): array
	{
		return [
			'FILTER_ID' => $this->getId(),
			'FILTER' => $this->getFilters($this->trashcanMode),
			'FILTER_PRESETS' => $this->getPresetFields($this->trashcanMode),
			'ENABLE_LIVE_SEARCH' => true,
			'ENABLE_LABEL' => true,
			'RESET_TO_DEFAULT_MODE' => true,
		];
	}

	private function getFilters(bool $trashcanMode): array
	{
		if ($trashcanMode)
		{
			return $this->getFiltersForTrashMode();
		}

		$filters = [
			[
				'id' => 'NAME',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_NAME'),
				'default' => true,
			],
			[
				'id' => 'ID',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_ID'),
				'type' => 'number',
			],
			[
				'id' => 'CREATE_TIME',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_CREATE_TIME'),
				'type' => 'date',
				'time' => true,
			],
			[
				'id' => 'UPDATE_TIME',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_UPDATE_TIME'),
				'type' => 'date',
				'time' => true,
			],
			Configuration::allowUseExtendedFullText() && ExtendedIndex::isReady() ? [
				'id' => 'SEARCH_BY_CONTENT',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_SEARCH_BY_CONTENT'),
				'type' => 'checkbox',
				'default' => true,
				'valueType' => 'numeric',
			]: null,
			[
				'id' => 'SEARCH_IN_CURRENT_FOLDER',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_SEARCH_IN_CURRENT_FOLDER'),
				'type' => 'checkbox',
				'default' => true,
				'valueType' => 'numeric',
			],
			[
				'id' => 'WITH_EXTERNAL_LINK',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_WITH_EXTERNAL_LINK'),
				'type' => 'list',
				'default' => false,
				'items' => [
					self::WITH_EXTERNAL_LINK => Loc::getMessage('DISK_FOLDER_FILTER_WITH_EXTERNAL_LINK_YES')
				],
			],
			[
				'id' => 'SHARED',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_SHARED'),
				'type' => 'list',
				'default' => false,
				'items' => [
					self::SHARED_FROM_ME => Loc::getMessage('DISK_FOLDER_FILTER_SHARED_FROM_ME'),
					self::SHARED_TO_ME => Loc::getMessage('DISK_FOLDER_FILTER_SHARED_TO_ME'),
				],
			],
			$this->getFileTypeField(),
		];

		return array_filter($filters);
	}

	private function getFileTypeField(): array
	{
		$items = [];
		foreach (self::FILE_TYPE_PHRASE_CODES as $fileType => $phraseCode)
		{
			$items[$fileType] = Loc::getMessage($phraseCode);
		}

		return [
			'id' => self::FILE_TYPE_FIELD_ID,
			'name' => Loc::getMessage('DISK_FOLDER_FILTER_FILE_TYPE'),
			'type' => 'list',
			'default' => true,
			'params' => [
				'multiple' => true,
			],
			'items' => $items,
		];
	}

	/**
	 * @throws ObjectException
	 * @throws NotImplementedException
	 */
	private function getPresetFields(bool $trashcanMode): array
	{
		if ($trashcanMode)
		{
			return $this->getPresetFieldsForTrashMode();
		}

		Options::calcDates(
			'UPDATE_TIME',
			[
				'UPDATE_TIME_datesel' => DateType::CURRENT_WEEK
			],
			$sevenDayBefore
		);

		$userGender = User::loadById(CurrentUser::get()->getId())->getPersonalGender();
		$sharedFromMePhrase = empty($userGender)
			? Loc::getMessage('DISK_FOLDER_FILTER_PRESETS_SHARED_FROM_ME')
			: Loc::getMessage("DISK_FOLDER_FILTER_PRESETS_SHARED_FROM_ME_$userGender");

		return [
			...$this->getFileTypePresetFields(),
			'recently_updated' => [
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_PRESETS_RECENTLY_UPDATED'),
				'default' => false,
				'fields' => $sevenDayBefore
			],
			'with_external_link' => [
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_PRESETS_WITH_EXTERNAL_LINK'),
				'default' => false,
				'fields' => [
					'WITH_EXTERNAL_LINK' => self::WITH_EXTERNAL_LINK
				]
			],
			'shared_to_me' => [
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_PRESETS_SHARED_TO_ME'),
				'default' => false,
				'fields' => [
					'SHARED' => self::SHARED_TO_ME
				]
			],
			'shared_from_me' => [
				'name' => $sharedFromMePhrase,
				'default' => false,
				'fields' => [
					'SHARED' => self::SHARED_FROM_ME
				]
			],
		];
	}

	/** One preset per file type: each one pins the multiple choice field to a single category. */
	private function getFileTypePresetFields(): array
	{
		$presets = [];
		foreach (self::FILE_TYPE_BY_PRESET_ID as $presetId => $fileType)
		{
			$presets[$presetId] = [
				'name' => Loc::getMessage(self::FILE_TYPE_PHRASE_CODES[$fileType]),
				'default' => false,
				'fields' => [
					self::FILE_TYPE_FIELD_ID => [$fileType],
				],
			];
		}

		return $presets;
	}

	private function getFiltersForTrashMode(): array
	{
		return [
			[
				'id' => 'NAME',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_NAME'),
				'default' => true,
			],
			[
				'id' => 'ID',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_ID'),
				'type' => 'number',
			],
			[
				'id' => 'CREATE_TIME',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_CREATE_TIME'),
				'type' => 'date',
				'time' => true,
			],
			[
				'id' => 'UPDATE_TIME',
				'name' => Loc::getMessage('DISK_FOLDER_FILTER_UPDATE_TIME'),
				'type' => 'date',
				'time' => true,
			],
			[
				'id' => 'DELETE_TIME',
				'name' => Loc::getMessage('DISK_TRASHCAN_FOLDER_FILTER_DELETE_TIME'),
				'type' => 'date',
				'time' => true,
			],
		];
	}

	/**
	 * @throws ObjectException
	 */
	private function getPresetFieldsForTrashMode(): array
	{
		Options::calcDates(
			'UPDATE_TIME',
			[
				'UPDATE_TIME_datesel' => DateType::CURRENT_WEEK
			],
			$sevenDayBeforeUpdated
		);

		Options::calcDates(
			'DELETE_TIME',
			[
				'DELETE_TIME_datesel' => DateType::CURRENT_WEEK
			],
			$sevenDayBeforeDeleted
		);

		return [
			'recently_deleted' => [
				'name' => Loc::getMessage('DISK_TRASHCAN_FOLDER_FILTER_PRESETS_RECENTLY_DELETED'),
				'default' => false,
				'fields' => $sevenDayBeforeDeleted
			],
			'recently_updated' => [
				'name' => Loc::getMessage('DISK_TRASHCAN_FOLDER_FILTER_PRESETS_RECENTLY_UPDATED'),
				'default' => false,
				'fields' => $sevenDayBeforeUpdated
			],
		];
	}
}