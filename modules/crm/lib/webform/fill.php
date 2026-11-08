<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage crm
 * @copyright 2001-2016 Bitrix
 */
namespace Bitrix\Crm\WebForm;

use Bitrix\Crm\Entity\Identificator;
use Bitrix\Crm\FileUploader\SiteFormFileUploaderController;

use Bitrix\Crm\WebForm\Agreement\SubmissionPolicy;
use Bitrix\Crm\WebForm\Limitations;
use Bitrix\Main;
use Bitrix\UI;

/*
 * Fill class.
 */
class Fill
{
	/** @var Form $form Form. */
	protected $form;
	/** @var array $values Values. */
	protected $values;
	/** @var array $consents Consents. */
	protected $consents = [];
	private array $explicitConsents = [];
	private array $displayedSubmitAgreements = [];
	/** @var int|string $trace Trace. */
	protected $trace;
	/** @var array $properties Properties. */
	protected $properties;
	/** @var Identificator\ComplexCollection $entities Entities. */
	protected $entities;
	/** @var bool $isFieldCheckingEnabled Is field checking enabled. */
	protected $isFieldCheckingEnabled = true;
	/** @var SubmissionPolicy|null $agreementPolicy Acceptance policy of the saved form. */
	private ?SubmissionPolicy $agreementPolicy = null;
	/** @var array Telemetry context. */
	protected $telemetryContext = [];

	/*
	 * Constructor.
	 */
	public function __construct(Form $form)
	{
		$this->form = $form;
	}

	/*
	 * Set values.
	 *
	 * @param array $values Values.
	 * @return $this
	 */
	public function setValues(array $values)
	{
		$this->values = $values;
		return $this;
	}

	/*
	 * Set consents.
	 *
	 * @param array $values Values.
	 * @return $this
	 */
	public function setConsents(array $consents)
	{
		$this->consents = $consents;
		return $this;
	}

	public function setExplicitConsents(array $explicitConsents): self
	{
		$this->explicitConsents = $explicitConsents;

		return $this;
	}

	public function setDisplayedSubmitAgreements(array $displayedSubmitAgreements): self
	{
		$this->displayedSubmitAgreements = $displayedSubmitAgreements;

		return $this;
	}

	/*
	 * Set field checking.
	 *
	 * @param bool $mode Mode.
	 * @return $this
	 */
	public function setFieldChecking(bool $mode)
	{
		$this->isFieldCheckingEnabled = $mode;
		return $this;
	}

	/*
	 * Set values.
	 *
	 * @param array $properties Properties.
	 * @return $this
	 */
	public function setProperties(array $properties)
	{
		$this->properties = $properties;
		return $this;
	}

	/*
	 * Set entities.
	 *
	 * @param Identificator\ComplexCollection $entities Entities.
	 * @return $this
	 */
	public function setEntities(Identificator\ComplexCollection $entities)
	{
		$this->entities = $entities;
		return $this;
	}

	/*
	 * Set values.
	 *
	 * @param Tracking\Trace|int|string $values Values.
	 * @return $this
	 */
	public function setTrace($trace)
	{
		$this->trace = $trace;
		return $this;
	}

	/**
	 * Set telemetry context.
	 *
	 * @param array $context Telemetry context.
	 * @return $this
	 */
	public function setTelemetryContext(array $context): self
	{
		$this->telemetryContext = $context;

		return $this;
	}

	/*
	 * Save form fill.
	 *
	 * Internal fills with field checking disabled do not present legal documents to the client.
	 *
	 * @return Result
	 */
	public function save()
	{
		$acceptedAgreementIds = [];
		if ($this->isFieldCheckingEnabled)
		{
			$agreements = $this->resolveAgreements();
			if (!$agreements->isSuccess())
			{
				return self::createRefusedResult($agreements->getErrorMessages());
			}

			$acceptedAgreementIds = $agreements->getData()['acceptedAgreementIds'] ?? [];
		}

		return $this->form->addResult(
			$this->getFilledFields(),
			[
				'ENTITIES' => $this->entities ? $this->entities->toSimpleArray(['typeId', 'id']) : [],
				'DISABLE_FIELD_CHECKING' => !$this->isFieldCheckingEnabled,
				'COMMON_FIELDS' => [],
				'PLACEHOLDERS' => $this->properties,
				'AGREEMENTS' => $acceptedAgreementIds,
				'STOP_CALLBACK' => false,
				'COMMON_DATA' => [
					'VISITED_PAGES' => [],
					'TRACE' => $this->trace
				],
				'TELEMETRY_CONTEXT' => $this->telemetryContext,
			]
		);
	}

	/**
	 * Result of a submission refused by the acceptance policy.
	 *
	 * The errors of the result model have no public setter, so they are filled by an heir at
	 * construction: an empty error list would let a caller printing errors run on to a result
	 * entity that was never created.
	 *
	 * @param string[] $errors
	 * @return Result
	 */
	private static function createRefusedResult(array $errors): Result
	{
		return new class(null, null, $errors) extends Result
		{
			public function __construct($id, ?array $params, array $errors)
			{
				parent::__construct($id, $params);

				$this->errors = $errors;
			}
		};
	}

	/**
	 * Resolve the accepted documents of this submission against the saved form policy.
	 *
	 * Public submission validates the policy before saving; save() also enforces it when field
	 * checking is enabled.
	 *
	 * @return Main\Result Data key `acceptedAgreementIds` holds the accepted identifiers.
	 */
	public function resolveAgreements(): Main\Result
	{
		$this->agreementPolicy ??= SubmissionPolicy::createFromForm($this->form);

		return $this->agreementPolicy->resolveAcceptance(
			$this->consents,
			$this->explicitConsents,
			$this->displayedSubmitAgreements,
		);
	}

	private function getFilledFields(): array
	{
		$fields = $this->form->getFieldsMap();
		foreach ($fields as $fieldKey => $field)
		{
			$fieldName = $field['name'];
			$fieldValues = isset($this->values[$fieldName]) ? $this->values[$fieldName] : [];
			if (!is_array($fieldValues))
			{
				$fieldValues = [$fieldValues];
			}

			if ($field['type'] == 'file')
			{
				$files = [];

				$fileController = new SiteFormFileUploaderController([
					'formId' => strval($this->form->getId()),
					'secCode' => $this->form->get()['SECURITY_CODE'] ?? '',
				]);
				$uploader = new UI\FileUploader\Uploader($fileController);

				foreach ($fieldValues as $fileData)
				{
					if (!empty($fileData['token']))
					{
						$pendingFiles = $uploader->getPendingFiles([$fileData['token']]);
						$pendingFile = $pendingFiles->getAll()[$fileData['token']] ?? null;

						if (!$pendingFile)
						{
							continue;
						}

						if (!$pendingFile->isValid())
						{
							$error = $pendingFile->getErrors()[0] ?? null;
							$errorMessage = $error?->getMessage() ?? '';
							$formId = $this->form->getId();

							\Bitrix\Crm\Service\Container::getInstance()->getLogger('Webform')
								->error("Failed to save file in form $formId for $fieldName. $errorMessage", [
									'formId' => $this->form->getId(),
									'fieldName' => $fieldName,
									'secCode' => $this->form->get()['SECURITY_CODE'] ?? '',
									'fingerprint' => $fileController->getFingerprint(),
									'token' => $fileData['token'],
									'error' => $error,
								])
							;

							continue;
						}

						$pendingFiles->makePersistent();
						$file = \CFile::MakeFileArray($pendingFile->getFileId());
					}
					else
					{
						$file = \CRestUtil::saveFile($fileData['content'], $fileData['name']);
					}

					$dailyLimiter = Limitations\DailyFileUploadLimit::instance();
					if ($dailyLimiter->isUsed())
					{
						$dailyLimiter->incrementByValue((int)($file['size'] ?? 0));
					}

					$files[] = $file;
				}

				$fieldValues = $files;
			}
			elseif ($field['type'] == 'phone')
			{
				$fieldValues = array_map(
					function ($value)
					{
						return preg_replace("/[^0-9+]/", '', $value);
					},
					$fieldValues
				);
			}
			elseif ($field['type'] === 'email')
			{
				$fieldValues = array_filter(
					$fieldValues,
					function ($value)
					{
						return check_email($value);
					}
				);
			}
			else if ($field['entity_field_name'] == 'COMMENTS')
			{
				$fieldValues = array_map(
					function ($value)
					{
						return nl2br(htmlspecialcharsbx($value));
					},
					$fieldValues
				);
			}

			$field['values'] = $fieldValues;
			$fields[$fieldKey] = $field;
		}

		return $fields;
	}
}
