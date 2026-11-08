<?php
namespace Bitrix\Translate\Controller\Import;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Translate;


/**
 * Harvester of the lang folder disposition.
 *
 * @internal
 */
class ImportCsv
	extends Translate\Controller\Action
	implements Translate\Controller\ITimeLimit, Translate\Controller\IProcessParameters
{
	use Translate\Controller\Stepper;
	use Translate\Controller\ProcessParams;

	private const PATH_ERROR_PHRASE = [
		Translate\IO\Path::ERROR_INVALID_PATH => 'TR_IMPORT_ERROR_BAD_FILEPATH',
		Translate\IO\Path::ERROR_NOT_LANG_FILE => 'TR_IMPORT_ERROR_FILE_NOT_LANG',
		Translate\IO\Path::ERROR_PATH_NOT_ALLOWED => 'TR_IMPORT_ERROR_BAD_FILEPATH',
	];

	private const MAX_SKIPPED_PATHS_REPORTED = 10;

	/** @var int */
	private $seekLine;

	/** @var string */
	protected static $documentRoot;
	/** @var string[] */
	protected static $enabledLanguages;
	/** @var string[] */
	protected static $sourceEncoding;
	/** @var boolean */
	protected static $isUtfMode;

	/** @var int Session tab counter. */
	private $tabId = 0;

	/** @var string */
	private $encodingIn;

	/** @var string */
	private $updateMethod;

	/** @var string */
	private $csvFilePath;

	/** @var Translate\IO\CsvFile */
	private $csvFile;


	/** @var string[] */
	private $languageList;

	/** @var string[] */
	private $columnList;

	/** @var int */
	private $importedPhraseCount = 0;

	/** @var string[] Reported messages of the skipped paths, keyed by the raw csv value. */
	private $skippedPaths = [];

	/** @var bool */
	private $hasUnreportedSkippedPaths = false;



	/**
	 * \Bitrix\Main\Engine\Action constructor.
	 *
	 * @param string $name Action name.
	 * @param Main\Engine\Controller $controller Parent controller object.
	 * @param array $config Additional configuration.
	 */
	public function __construct($name, Main\Engine\Controller $controller, array $config = [])
	{
		$fields = ['tabId', 'encodingIn', 'updateMethod', 'csvFilePath', 'seekLine', 'importedPhrasesCount'];

		$this->keepField($fields);

		// kept between the steps, but never taken from the request: the value comes from the csv
		$this->keepField(['skippedPaths', 'hasUnreportedSkippedPaths']);

		foreach ($fields as $key)
		{
			if (!empty($config[$key]))
			{
				$this->{$key} = $config[$key];
			}
		}

		self::$documentRoot = \rtrim(Translate\IO\Path::tidy(Main\Application::getDocumentRoot()), '/');

		self::$enabledLanguages = Translate\Config::getEnabledLanguages();

		foreach (self::$enabledLanguages as $languageId)
		{
			self::$sourceEncoding[$languageId] = \mb_strtolower(Main\Localization\Translation::getSourceEncoding($languageId));
		}

		parent::__construct($name, $controller, $config);
	}

	/**
	 * Runs controller action.
	 *
	 * @param boolean $runBefore Flag to run onBeforeRun event handler.
	 *
	 * @return array
	 */
	public function run($runBefore = false)
	{
		if ($runBefore)
		{
			$this->onBeforeRun();
		}

		$this->csvFile = new Translate\IO\CsvFile($this->csvFilePath);

		$this->csvFile
			->setFieldsType(Translate\IO\CsvFile::FIELDS_TYPE_WITH_DELIMITER)
			->setFirstHeader(false)
		;

		if (!$this->csvFile->openLoad())
		{
			$this->addError(new Main\Error(Loc::getMessage('TR_IMPORT_EMPTY_FILE_ERROR')));

			return [
				'STATUS' => Translate\Controller\STATUS_COMPLETED
			];
		}
		if (!$this->verifyCsvFile())
		{
			return [
				'STATUS' => Translate\Controller\STATUS_COMPLETED
			];
		}

		if ($this->csvFile->hasUtf8Bom())
		{
			$this->encodingIn = 'utf-8';
		}

		if ($this->isNewProcess)
		{
			$this->clearProgressParameters();

			$this->totalItems = 0;
			while ($csvRow = $this->csvFile->fetch())
			{
				$this->totalItems ++;
			}

			$this->processedItems = 0;
			$this->seekLine = 0;

			$this->saveProgressParameters();

			return [
				'STATUS' => Translate\Controller\STATUS_PROGRESS,
				'PROCESSED_ITEMS' => 0,
				'TOTAL_ITEMS' => $this->totalItems,
			];
		}
		else
		{
			$progressParams = $this->getProgressParameters();

			if (isset($progressParams['totalItems']))
			{
				$this->totalItems = $progressParams['totalItems'];
			}
			if (isset($progressParams['seekLine']))
			{
				$this->seekLine = $progressParams['seekLine'];
			}
			if (isset($progressParams['importedPhrasesCount']))
			{
				$this->importedPhraseCount = $progressParams['importedPhrasesCount'];
			}
			if (isset($progressParams['skippedPaths']))
			{
				$this->skippedPaths = $progressParams['skippedPaths'];
			}
			if (isset($progressParams['hasUnreportedSkippedPaths']))
			{
				$this->hasUnreportedSkippedPaths = $progressParams['hasUnreportedSkippedPaths'];
			}
		}

		return $this->performStep('runImporting');
	}

	/**
	 * Imports data from csv file.
	 *
	 * @return array
	 */
	private function runImporting(): array
	{
		$fileIndex = $this->columnList['file'];
		$keyIndex = $this->columnList['key'];

		$currentLine = 0;
		$maxLinePortion = 500;
		$hasFinishedReading = false;

		while (true)
		{
			$linePortion = 0;
			$phraseList = [];

			while ($csvRow = $this->csvFile->fetch())
			{
				$currentLine ++;

				if ($this->seekLine > 0)
				{
					if ($currentLine <= $this->seekLine)
					{
						continue;
					}
				}

				if (
					!\is_array($csvRow) ||
					empty($csvRow) ||
					(\count($csvRow) == 1 && ($csvRow[0] === null || $csvRow[0] === ''))
				)
				{
					continue;
				}

				$rowErrors = [];

				$filePath = (isset($csvRow[$fileIndex]) ? $csvRow[$fileIndex] : '');
				$key = (isset($csvRow[$keyIndex]) ? $csvRow[$keyIndex] : '');
				if ($filePath == '' || $key == '')
				{
					if ($filePath == '')
					{
						$rowErrors[] = Loc::getMessage('TR_IMPORT_ERROR_DESTINATION_FILEPATH_ABSENT');
					}
					if ($key == '')
					{
						$rowErrors[] = Loc::getMessage('TR_IMPORT_ERROR_PHRASE_CODE_ABSENT');
					}
					$this->addError(new Main\Error(Loc::getMessage(
						'TR_IMPORT_ERROR_LINE_FILE_EXT',
						[
							'#LINE#' => ($currentLine + 1),
							'#ERROR#' => implode('; ', $rowErrors)
						]
					)));

					continue;
				}

				$linePortion ++;

				if (!isset($phraseList[$filePath]))
				{
					$phraseList[$filePath] = [];
				}
				foreach ($this->languageList as $languageId)
				{
					if (!isset($phraseList[$filePath][$languageId]))
					{
						$phraseList[$filePath][$languageId] = [];
					}

					$langIndex = $this->columnList[$languageId];
					if (!isset($csvRow[$langIndex]) || (empty($csvRow[$langIndex]) && $csvRow[$langIndex] !== '0'))
					{
						continue;
					}

					//$phrase = str_replace("\\\\", "\\", $csvRow[$langIndex]);
					$phrase = $csvRow[$langIndex];

					$encodingOut = self::$sourceEncoding[$languageId];

					if (!empty($this->encodingIn) && $this->encodingIn !== $encodingOut)
					{
						$phrase = Main\Text\Encoding::convertEncoding($phrase, $this->encodingIn, $encodingOut);
					}

					$checked = true;
					if ($encodingOut === 'utf-8')
					{
						$validPhrase = \preg_replace("/[^\x01-\x7F]/", '', $phrase);// remove ASCII characters
						if ($validPhrase !== $phrase)
						{
							$checked = Translate\Text\StringHelper::validateUtf8OctetSequences($phrase);
						}
						unset($validPhrase);
					}

					if ($checked)
					{
						$phraseList[$filePath][$languageId][$key] = $phrase;
					}
					else
					{
						$rowErrors[] = Loc::getMessage('TR_IMPORT_ERROR_NO_VALID_UTF8_PHRASE', ['#LANG#' => $languageId]);
					}

					unset($checked, $phrase);
				}

				if (!empty($rowErrors))
				{
					$this->addError(new Main\Error(Loc::getMessage(
						'TR_IMPORT_ERROR_LINE_FILE_BIG',
						[
							'#LINE#' => ($currentLine + 1),
							'#FILENAME#' => $filePath,
							'#PHRASE#' => $key,
							'#ERROR#' => implode('; ', $rowErrors),
						]
					)));
				}
				unset($rowErrors);


				if ($linePortion >= $maxLinePortion)
				{
					break;
				}
			}

			if ($csvRow === null)
			{
				$hasFinishedReading = true;
			}
			unset($csvRow);

			$this->processedItems += $linePortion;

			foreach ($phraseList as $filePath => $translationList)
			{
				foreach ($translationList as $languageId => $fileMessages)
				{
					if (empty($fileMessages))
					{
						continue;
					}

					$pathResult = Translate\IO\Path::resolveAllowedLangFile($filePath, $languageId);
					if (!$pathResult->isSuccess())
					{
						// an action error would turn the step into a completed one and leave the rest
						// of the file unimported, so a rejected path is reported as a warning
						$this->skipPath($filePath, $pathResult->getErrors()[0]->getCode());

						// the language code takes part in the check, so a neighbouring language of the
						// same row can still be importable; the report is deduplicated by the path
						continue;
					}

					$langFilePath = $pathResult->getData()['path'];

					$fullPath = self::$documentRoot. $langFilePath;
					$fullPath = Main\Localization\Translation::convertLangPath($fullPath, $languageId);

					$langFile = new Translate\File($fullPath);
					$langFile->setLangId($languageId);
					$langFile->setOperatingEncoding(self::$sourceEncoding[$languageId]);

					$errorCountBeforeLoad = \count($this->getErrors());

					if (!$langFile->loadTokens())
					{
						if (!$langFile->load() && $langFile->hasErrors())
						{
							foreach ($langFile->getErrors() as $error)
							{
								if ($error->getCode() !== 'EMPTY_CONTENT')
								{
									$this->addError($error);
								}
							}
						}
					}
					if (\count($this->getErrors()) > $errorCountBeforeLoad)
					{
						continue;
					}

					$hasDataToUpdate = false;

					/** @var \ArrayAccess $langFile */
					foreach ($fileMessages as $key => $phrase)
					{
						switch ($this->updateMethod)
						{
							// import only new messages
							case Translate\Controller\Import\Csv::METHOD_ADD_ONLY:
								if (!isset($langFile[$key]) || (empty($langFile[$key]) && $langFile[$key] !== '0'))
								{
									$langFile[$key] = $phrase;
									$hasDataToUpdate = true;
									$this->importedPhraseCount ++;
								}
								break;

							// update only existing messages
							case Translate\Controller\Import\Csv::METHOD_UPDATE_ONLY:
								if (isset($langFile[$key]) && $langFile[$key] !== $phrase)
								{
									$langFile[$key] = $phrase;
									$hasDataToUpdate = true;
									$this->importedPhraseCount ++;
								}
								break;


							// import new messages and replace all existing with new ones
							case Translate\Controller\Import\Csv::METHOD_ADD_UPDATE:
								if ($langFile[$key] !== $phrase)
								{
									$langFile[$key] = $phrase;
									$hasDataToUpdate = true;
									$this->importedPhraseCount ++;
								}
								break;
						}
					}

					if ($hasDataToUpdate)
					{
						// backup
						if ($langFile->isExists() && Translate\Config::needToBackUpFiles())
						{
							if (!$langFile->backup())
							{
								$this->addError(new Main\Error(
									Loc::getMessage('TR_IMPORT_ERROR_CREATE_BACKUP', ['#FILE#' => $langFilePath])
								));
							}
						}

						// sort phrases by key
						if (Translate\Config::needToSortPhrases())
						{
							if (\in_array($languageId, Translate\Config::getNonSortPhraseLanguages()) === false)
							{
								$langFile->sortPhrases();
							}
						}

						try
						{
							if (!$langFile->save())
							{
								if ($langFile->hasErrors())
								{
									$this->addErrors($langFile->getErrors());
								}
							}
						}
						catch (Main\IO\IoException $exception)
						{
							if (!$langFile->isExists())
							{
								$this->addError(new Main\Error(
									Loc::getMessage('TR_IMPORT_ERROR_WRITE_CREATE', ['#FILE#' => $langFilePath])
								));
							}
							else
							{
								$this->addError(new Main\Error(
									Loc::getMessage('TR_IMPORT_ERROR_WRITE_UPDATE', ['#FILE#' => $langFilePath])
								));
							}
						}
					}
				}
			}

			if ($this->instanceTimer()->hasTimeLimitReached())
			{
				$this->seekLine = $currentLine;
				break;
			}

			if ($hasFinishedReading)
			{
				$this->declareAccomplishment();
				$this->clearProgressParameters();
				break;
			}
		}

		$this->csvFile->close();

		if ($this->instanceTimer()->hasTimeLimitReached() !== true)
		{
			$this->declareAccomplishment();
			$this->clearProgressParameters();
		}

		$result = [
			'PROCESSED_ITEMS' => $this->processedItems,
			'TOTAL_ITEMS' => $this->totalItems,
			'TOTAL_PHRASES' => $this->importedPhraseCount,
		];

		if (!empty($this->skippedPaths))
		{
			// the client escapes a warning, so the message stays plain text
			$result['WARNING'] = \implode('; ', $this->skippedPaths);
			if ($this->hasUnreportedSkippedPaths)
			{
				$result['WARNING'] .= ' ...';
			}
		}

		return $result;
	}


	/**
	 * Registers a csv row rejected by the path check. Such a row does not stop the import.
	 *
	 * @param string $filePath Raw value of the csv file column.
	 * @param string $errorCode Reason code returned by the path check.
	 *
	 * @return void
	 */
	private function skipPath(string $filePath, string $errorCode): void
	{
		if (isset($this->skippedPaths[$filePath]))
		{
			return;
		}

		if (\count($this->skippedPaths) >= self::MAX_SKIPPED_PATHS_REPORTED)
		{
			$this->hasUnreportedSkippedPaths = true;

			return;
		}

		$this->skippedPaths[$filePath] = Loc::getMessage(
			self::PATH_ERROR_PHRASE[$errorCode] ?? 'TR_IMPORT_ERROR_BAD_FILEPATH',
			['#FILE#' => $filePath]
		);
	}


	/**
	 * Validates uploaded csv file.
	 *
	 * @return boolean
	 */
	private function verifyCsvFile(): bool
	{
		$testDelimiters = [
			Translate\IO\CsvFile::DELIMITER_TZP,
			Translate\IO\CsvFile::DELIMITER_TAB,
			Translate\IO\CsvFile::DELIMITER_ZPT,
		];
		foreach ($testDelimiters as $delimiter)
		{
			$this->csvFile->setFieldDelimiter($delimiter);

			$this->csvFile->moveFirst();
			$rowHead = $this->csvFile->fetch();
			if (
				!\is_array($rowHead)
				|| empty($rowHead)
				|| empty($rowHead[0])
				|| (\count($rowHead) < 3)
			)
			{
				continue;
			}

			break;
		}

		if (
			!is_array($rowHead)
			|| empty($rowHead)
			|| empty($rowHead[0])
			|| (count($rowHead) < 3)
		)
		{
			$this->addError(new Main\Error(Loc::getMessage('TR_IMPORT_ERR_EMPTY_FIRST_ROW')));

			return false;
		}

		$this->languageList = self::$enabledLanguages;
		$this->columnList = array_flip($rowHead);
		foreach ($this->languageList as $keyLang => $langID)
		{
			if (!isset($this->columnList[$langID]))
			{
				unset($this->languageList[$keyLang]);
			}
		}
		if (!isset($this->columnList['file']))
		{
			$this->addError(new Main\Error(Loc::getMessage('TR_IMPORT_ERR_DESTINATION_FIELD_ABSENT')));
		}
		if (!isset($this->columnList['key']))
		{
			$this->addError(new Main\Error(Loc::getMessage('TR_IMPORT_ERR_PHRASE_CODE_FIELD_ABSENT')));
		}
		if (empty($this->languageList))
		{
			$this->addError(new Main\Error(Loc::getMessage('TR_IMPORT_ERR_LANGUAGE_LIST_ABSENT')));
		}

		return count($this->getErrors()) === 0;
	}
}