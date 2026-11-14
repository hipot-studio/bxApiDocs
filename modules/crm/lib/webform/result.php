<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage crm
 * @copyright 2001-2016 Bitrix
 */
namespace Bitrix\Crm\WebForm;

use Bitrix\Crm\V2\Internal\Service\LegalRegistry;

use Bitrix\Main\Localization\Loc;
use Bitrix\Crm\WebForm\Internals\ResultTable;
use Bitrix\Crm\WebForm\Internals\Model;
use Bitrix\Crm\WebForm\Internals\FormCounterTable;
use Bitrix\Crm\WebForm\Internals\FormCounterDailyTable;
use Bitrix\Main\Entity\Result as EntityResult;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

class Result extends Model
{
	/**@var $resultEntity ResultEntity Result entity. */
	protected $resultEntity;

	/** @var string $resultUrl Result Url. */
	protected $url;
	private bool $legalWriting = false;
	private bool $legalReplay = false;
	private ?array $legalOutcome = null;

	protected function getClassTable()
	{
		return 'ResultTable';
	}

	/*
	 * Get result entity.
	 *
	 * @return ResultEntity
	 * */
	public function getResultEntity()
	{
		return $this->resultEntity;
	}

	/**
	 * Get result url.
	 *
	 * @return string
	 */
	public function getUrl()
	{
		if (preg_match('#^(?:/|https?://)#', $this->url))
		{
			return $this->url;
		}

		return null;
	}

	/**
	 * Set result url.
	 *
	 * @param string $url Url.
	 * @return void
	 */
	public function setUrl(string $url = null)
	{
		$this->url = $url;
	}

	public function load($id)
	{
		$class = $this->getClassTable();

		$this->id = $id;
		$result = $class::getRowById($id);
		if(!$result)
		{
			return;
		}

		$this->params = $result;
	}

	public function save($onlyCheck = false)
	{
		if (!$this->legalWriting && $this->id === null && !empty($this->params['LEGAL_SUBMISSION']))
		{
			$this->saveLegalResult();
			return;
		}
		$fields = $this->params;
		$fieldsResult = array(
			'FORM_ID' => $fields['FORM_ID'],
			'ORIGIN_ID' => $fields['ORIGIN_ID'],
			'DATE_INSERT' => new DateTime()
		);

		if(!$this->check())
		{
			return;
		}

		if($this->id)
		{
			$result = ResultTable::update($this->id, $fieldsResult);
		}
		else
		{
			$result = ResultTable::add($fieldsResult);
			$this->id = $result->getId();
			if($this->id > 0)
			{
				$this->addEntity();
				FormCounterDailyTable::incrementEndFill(new Date(), (int)$fields['FORM_ID']);
				FormCounterTable::incCounters(
					$fields['FORM_ID'],
					array(
						'END_FILL',
						'MONEY' => $this->getProductSum()
					)
				);
			}
		}

		$this->prepareResult($result);
	}

	public function isLegalReplay(): bool { return $this->legalReplay; }

	public function getLegalOutcome(): ?array { return $this->legalOutcome; }

	public function getLegalReplayResponse(): array
	{
		$response = ['resultId' => (int)$this->id, 'replayed' => true, 'recovered' => true];
		if (isset($this->legalOutcome['response']))
		{
			$response += $this->legalOutcome['response'];
			$response['redirect'] = [
				'url' => $this->getUrl(),
				'delay' => $response['redirect']['delay'],
			];
			if ($response['pay'] && !empty($this->legalOutcome['orderId']))
			{
				$urlInfo = \Bitrix\SalesCenter\Integration\LandingManager::getInstance()->getUrlInfoByOrderId(
					$this->legalOutcome['orderId'],
					[
						'paymentId' => $this->legalOutcome['paymentId'] ?: 0,
						'excludedPS' => $this->legalOutcome['response']['excludedPaySystems'] ?? [],
					],
				);
				$response['redirect']['url'] = $this->paymentUrl($urlInfo['url'] ?? null);
			}
			elseif ($response['pay'] && !empty($this->legalOutcome['invoiceId']))
			{
				$response['redirect']['url'] = $this->paymentUrl(
					\CAllCrmInvoice::getPublicLink($this->legalOutcome['invoiceId']),
				);
			}
			unset($response['excludedPaySystems']);

			return $response;
		}
		if (!empty($this->legalOutcome['url']) && empty($this->legalOutcome['invoiceId'])
			&& empty($this->legalOutcome['orderId']) && $this->getUrl() !== null)
		{
			$response['redirect'] = ['url' => $this->getUrl()];
		}

		return $response;
	}

	private function paymentUrl(?string $url): ?string
	{
		if (!$url) { return null; }
		$redirect = new \Bitrix\Main\Web\Uri($url);
		$redirect->addParams(['form_id' => $this->legalOutcome['formId']]);
		$this->setUrl($redirect->getLocator());

		return $this->getUrl();
	}


	public static function fromLegalReceipt(array $receipt): self
	{
		$result = new self(null);
		$result->restoreLegalReceipt($receipt);

		return $result;
	}

	private function restoreLegalReceipt(array $receipt): void
	{
		$this->id = $receipt['resultId'];
		$this->legalReplay = true;
		$this->legalOutcome = $receipt['outcome'];
		$this->url = $receipt['outcome']['url'] ?? null;
		$this->resultEntity = ResultEntity::fromLegalOutcome($receipt['outcome']);
	}

	private function saveLegalResult(): void
	{
		$legal = $this->params['LEGAL_SUBMISSION'];
		$service = new LegalRegistry\FormSubmissionService();
		$recovery = new LegalRegistry\FormSubmissionRecovery();
		try
		{
			$receipt = $service->commitResult((int)$this->params['FORM_ID'], $legal['submissionId'], $legal['requestHash'],
				function (): array {
					$this->legalWriting = true;
					try
					{
						if (!$this->check()) { throw new \UnexpectedValueException('CRM_RESULT_REJECTED'); }
						$this->save();
					}
					finally { $this->legalWriting = false; }
					if ($this->hasErrors() || !$this->id || !$this->resultEntity)
					{
						throw new \RuntimeException('STORAGE_ERROR');
					}
					$outcome = [
						'entities' => $this->resultEntity->getResultEntities(),
						'activityId' => $this->resultEntity->getActivityId() ?: null,
						'orderId' => $this->resultEntity->getOrderId() ?: null,
						'paymentId' => $this->resultEntity->getPaymentId() ?: null,
						'invoiceId' => $this->resultEntity->getInvoiceId() ?: null,
						'url' => $this->params['FORM']['RESULT_SUCCESS_URL'] ?? null,
						'response' => [
							'pay' => ($this->params['FORM']['IS_PAY'] ?? null) === 'Y',
							'message' => $this->params['FORM']['RESULT_SUCCESS_TEXT'],
							'gid' => $this->resultEntity->getTrace()->getGid(),
							'redirect' => [
								'delay' => (int)($this->params['FORM']['FORM_SETTINGS']['REDIRECT_DELAY'] ?? Form::REDIRECT_DELAY),
							],
							'refill' => [
								'active' => ($this->params['FORM']['FORM_SETTINGS']['REFILL']['ACTIVE'] ?? 'N') === 'Y',
								'caption' => $this->params['FORM']['FORM_SETTINGS']['REFILL']['CAPTION'] ?? '',
							],
							'excludedPaySystems' => $this->params['FORM']['FORM_SETTINGS']['DISABLED_PAY_SYSTEMS'] ?? [],
						],
					];
					if ($outcome['orderId'] !== null || $outcome['invoiceId'] !== null) { $outcome['url'] = null; }
					$this->legalOutcome = $outcome;
					return ['resultId' => (int)$this->id, 'outcome' => $outcome];
				});
		}
		catch (\Throwable $error)
		{
			$this->id = null;
			$this->resultEntity = null;
			$code = $error->getMessage();
			if ($code === 'CRM_RESULT_REJECTED') { $recovery->markFailed($legal['submissionId'], $legal['requestHash']); }
			else { $service->recordCrmFailure((int)$this->params['FORM_ID'], $legal['submissionId'], $legal['requestHash']); }
			if ($this->errors === [])
			{
				$this->errors[] = in_array($code, [
					'INVALID_PAYLOAD', 'INVALID_STATE', 'IDEMPOTENCY_CONFLICT', 'POLICY_UNAVAILABLE', 'FEATURE_UNAVAILABLE',
				], true) ? $code : 'STORAGE_ERROR';
			}
			return;
		}
		if ($receipt['replay']) { $this->restoreLegalReceipt($receipt); }
		$recovery->complete($legal['submissionId'], $legal['requestHash']);
		if (!$receipt['replay'])
		{
			try { $this->resultEntity->runDeferredEffects(); }
			catch (\Throwable)
			{
				\Bitrix\Crm\Service\Container::getInstance()->getLogger('Webform')->error(
					'Legal form post-commit effect failed.', ['submissionId' => $legal['submissionId']],
				);
			}
		}
	}

	public function check()
	{
		$fields = $this->params;
		$fieldsResult = array(
			'FORM_ID' => $fields['FORM_ID'],
			'DATE_INSERT' => new DateTime()
		);

		$result = new EntityResult;
		ResultTable::checkFields($result, $this->id, $fieldsResult);
		$this->prepareResult($result);

		if(!$this->hasErrors())
		{
			$this->checkFields();
		}

		return !$this->hasErrors();
	}

	protected function checkFields()
	{
		$result = true;
		$fields = $this->params['FIELDS'];
		foreach($fields as $field)
		{
			if(!$this->checkField($field))
			{
				$result = false;
			}
		}

		return $result;
	}

	protected function getProductSum()
	{
		$productSum = 0;
		foreach($this->params['PRODUCTS'] as $product)
		{
			$productSum += (int) $product['PRICE'];
		}

		return $productSum;
	}

	public function getFieldsForAdd()
	{
		$fieldsForAdd = array();

		$fields = $this->params['FIELDS'];
		foreach($fields as $field)
		{
			if($field['hidden'])
			{
				continue;
			}

			if(count($field['values']) == 0)
			{
				continue;
			}

			$fieldsForAdd[] = array(
				'type' => '',
				'name' => '',
				'caption' => '',
			);

			foreach($field['values'] as $value)
			{
				foreach($field['items'] as $item)
				{
					if($item['value'] != $value)
					{
						continue;
					}

					$fieldsForAdd['values'][] = $item;
					break;
				}
			}
		}

		return $fieldsForAdd;
	}

	protected function checkField($field)
	{
		if($field['hidden'] || 1)
		{
			return true;
		}

		$values = array();
		foreach($field['values'] as $value)
		{
			if(is_string($value))
			{
				$value = trim($value);
			}
			else
			{
				trimArr($value);
			}

			if(!$value && $value !== '0' && $value !== 0)
			{
				continue;
			}

			$values[] = $value;
		}


		if($field['required'] && count($values) == 0 && empty($this->params['DISABLE_FIELD_CHECKING']))
		{
			$this->errors[] = Loc::getMessage('CRM_WEBFORM_RESULT_ERROR_REQUIRED_FIELD_EMPTY', array('%field%' => $field['caption']));
			return false;
		}
		else if(count($values) == 0)
		{
			return true;
		}

		$result = true;
		switch($field['type'])
		{
			case 'checkbox':
			case 'radio':
			case 'list':
				$itemValues = array();
				foreach($field['items'] as $item)
				{
					$itemValues[] = $item['value'];
				}

				$result = array_intersect($values, $itemValues) > 0;
				break;

			case 'email':
				foreach($values as $value)
				{
					if(!$this->checkEmail($value))
					{
						$result = false;
						break;
					}
				}
				break;
			case 'phone':
				foreach($values as $value)
				{
					if(!$this->checkPhone($value))
					{
						$result = false;
						break;
					}
				}
				break;
			case 'int':
				foreach($values as $value)
				{
					if(!$this->checkInt($value))
					{
						$result = false;
						break;
					}
				}
				break;
		}


		if(!$result)
		{
			$this->errors[] = Loc::getMessage('CRM_WEBFORM_RESULT_ERROR_REQUIRED_FIELD_EMPTY', array('%field%' => $field['caption']));
		}

		return $result;
	}

	protected function checkEmail($value)
	{
		return check_email($value);
	}

	protected function checkInt($value)
	{
		return is_numeric($value);
	}

	protected function checkPhone($value)
	{
		return true;
	}

	public static function formatFieldsByTemplate(
		array $fields,
		$fieldTemplate = "%caption%%required%: %values%\n",
		$valueTemplate = "%value%\n",
		$valueListTemplate = "\n%value%",
		?string $languageId = null,
	)
	{
		$result = '';
		foreach($fields as $field)
		{
			if(!$field || !is_array($field['value']))
			{
				continue;
			}

			$values = array();
			foreach($field['value'] as $value)
			{
				if(is_array($value))
				{
					$values[] = htmlspecialcharsbx($value['title'] ?? $value['name']);

				}
				else
				{
					$values[] = htmlspecialcharsbx($value);
				}
			}

			// format values
			$displayedValues = '';
			foreach ($values as $value)
			{
				switch ($field['type'])
				{
					case Internals\FieldTable::TYPE_ENUM_CHECKBOX:
						if (in_array($value, ['Y', 'N']))
						{
							EntityFieldProvider::getBooleanFieldItems();

							$codePostfix = $value === 'Y' ? 'YES' : 'NO';
							$code = "CRM_WEBFORM_FIELD_PROVIDER_{$codePostfix}";

							$value = Loc::getMessage($code, null, $languageId);
						}
						break;
					case Internals\FieldTable::TYPE_ENUM_DATETIME:
						if (is_numeric($value))
						{
							$value = DateTime::createFromTimestamp($value)->toString();
						}
						break;
				}

				$displayedValues .= str_replace(
					array('%value%'),
					array($value),
					($valueListTemplate && count($values) > 1) ? $valueListTemplate : $valueTemplate
				);
			}

			// format field
			$result .= str_replace(
				array(
					'%caption%',
					'%required%',
					'%values%'
				),
				array(
					htmlspecialcharsbx($field['caption']),
					$field['required'] ? '*' : '',
					$displayedValues
				),
				$fieldTemplate
			);
		}

		return $result;
	}

	protected function addEntity()
	{
		$scheme = $this->params['ENTITY_SCHEME'];
		$fields = $this->params['FIELDS'];
		$presetFields = $this->params['PRESET_FIELDS'];
		$products = $this->params['PRODUCTS'];
		$currencyId = $this->params['CURRENCY_ID'];
		$assignedById = $this->params['ASSIGNED_BY_ID'];
		$activityFields = $this->params['ACTIVITY_FIELDS'];
		$invoiceSettings = $this->params['INVOICE_SETTINGS'];
		$duplicateMode = $this->params['DUPLICATE_MODE'];
		$commonFields = $this->params['COMMON_FIELDS'];
		$commonData = $this->params['COMMON_DATA'];
		$placeholders = $this->params['PLACEHOLDERS'];
		$isCallback = $this->params['IS_CALLBACK'];
		$callbackPhone = $this->params['CALLBACK_PHONE'];
		$entities = $this->params['ENTITIES'];
		$agreements = $this->params['AGREEMENTS'];

		$resultEntity = new ResultEntity;
		if (!empty($this->params['LEGAL_SUBMISSION'])) { $resultEntity->deferExternalEffects(); }
		$resultEntity->setFormData($this->params['FORM']);
		$resultEntity->setFormId($this->params['FORM_ID']);
		$resultEntity->setResultId($this->id);
		$resultEntity->setAssignedById($assignedById);
		$resultEntity->setPresetFields($presetFields);
		$resultEntity->setCommonFields($commonFields);
		$resultEntity->setCommonData($commonData);
		$resultEntity->setPlaceholders($placeholders);
		$resultEntity->setProductRows($products);
		$resultEntity->setCurrencyId($currencyId);
		$resultEntity->setActivityFields($activityFields);
		$resultEntity->setInvoiceSettings($invoiceSettings);
		$resultEntity->setDuplicateMode($duplicateMode);
		$resultEntity->setCallback($isCallback, $callbackPhone);
		$resultEntity->setEntities($entities);
		$resultEntity->setAgreements($agreements);

		$resultEntity->add($scheme, $fields);
		$this->resultEntity = $resultEntity;
	}
}
