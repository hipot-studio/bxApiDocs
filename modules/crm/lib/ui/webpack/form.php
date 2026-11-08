<?php

namespace Bitrix\Crm\UI\Webpack;

use Bitrix\Main\ModuleManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Crm\UI\Webpack;
use Bitrix\Crm\WebForm;

/**
 * Class Form
 *
 * @package Bitrix\Crm\UI\Webpack\Form
 */
class Form extends Webpack\Base
{
	protected static $type = 'form';

	/** @var WebForm\Form $form */
	protected $form;

	/** @var array $additionalOptions */
	protected $additionalOptions = [];

	/** @var array $additionalFormOptions */
	protected $additionalFormOptions = [];

	/** @var int $cacheTtl Cache ttl. */
	protected $cacheTtl = 180;

	/**
	 * Get instance.
	 *
	 * @param int $formId Form ID.
	 * @return static
	 */
	public static function instance($formId)
	{
		return new static($formId);
	}

	/**
	 * Rebuild resources.
	 *
	 * @return string
	 */
	public static function rebuildResources()
	{
		$isSuccess = (
			Form\Polyfill::instance()->build() &&
			Form\ResourceBooking::instance()->build() &&
			Form\Booking::instance()->build() &&
			Form\App::instance()->build()
		);

		if ($isSuccess && ModuleManager::isModuleInstalled('bitrix24'))
		{
			static::addCheckResourcesAgent();
		}

		return $isSuccess;
	}

	/**
	 * Add check resources agent.
	 *
	 * @return void
	 */
	public static function addCheckResourcesAgent()
	{
		if (!ModuleManager::isModuleInstalled('bitrix24'))
		{
			return;
		}

		$agentName = static::class . "::checkResourcesFileExistsAgent();";
		\CAgent::RemoveAgent($agentName, 'crm');
		\CAgent::AddAgent(
			$agentName,
			'crm', "N", 60, "", "Y",
			\ConvertTimeStamp(time()+\CTimeZone::GetOffset()+300, "FULL")
		);
	}

	/**
	 * Rebuild resources agent.
	 *
	 * @return string
	 */
	public static function rebuildResourcesAgent()
	{
		if (static::rebuildResources())
		{
			return '';
		}
		else
		{
			return static::class . '::rebuildResourcesAgent();';
		}
	}

	/**
	 * Check resources file exists.
	 *
	 * @return string
	 */
	public static function checkResourcesFileExistsAgent()
	{
		$polyfill = Form\Polyfill::instance();
		$resourceBooking = Form\ResourceBooking::instance();
		$booking = Form\Booking::instance();
		$app = Form\App::instance();

		if (
			$polyfill->checkFileExists()
			&& $resourceBooking->checkFileExists()
			&& $booking->checkFileExists()
			&& $app->checkFileExists()
		)
		{
			return '';
		}
		else
		{
			return static::class . '::rebuildResourcesAgent();';
		}
	}

	/**
	 * Rebuild agent.
	 *
	 * @param int $formId Form ID.
	 * @return string
	 */
	public static function rebuildAgent($formId)
	{
		if ((new static($formId))->build())
		{
			return '';
		}
		else
		{
			return '\\Bitrix\\Crm\\UI\\Webpack\\Form::rebuildAgent();';
		}
	}

	/**
	 * Configure. Set extensions and modules to controller.
	 *
	 * @return void
	 */
	public function configure()
	{
		if (!$this->form)
		{
			$this->form = new WebForm\Form($this->getId());
		}

		$data = $this->form->get();
		$isCloud = Loader::includeModule('bitrix24');

		$this->fileDir = 'form';
		$this->fileName = str_replace(
			['#id#', '#sec#'],
			[$this->getId(), $data['SECURITY_CODE']],
			$isCloud ? 'loader_#id#.js' : 'loader_#id#_#sec#.js'
		);

		$name = 'crm.site.form.embed.unit';
		$this->addExtension($name);
		$module = $this->getModule($name);
		$module->getProfile()->setCallParameter($this->getCallParameter());

		$this->embeddedModuleName = 'crm.site.form.unit.loader';
	}

	protected function configureFile()
	{
		if ($this->form)
		{
			$sec = $this->form->get()['SECURITY_CODE'] ?? '';
		}
		else
		{
			$sec = WebForm\Internals\FormTable::getRow([
				'select' => ['SECURITY_CODE'],
				'filter' => ['=ID' => $this->getId()]
			])['SECURITY_CODE'] ?? '';
		}

		$this->configureFormEmbeddedScript([
			'action' => 'inline',
			'sec' => $sec,
		]);
	}

	protected function getCallParameter()
	{
		if (!$this->form)
		{
			$this->form = new WebForm\Form($this->getId());
		}

		$formOptions = (new WebForm\Embed\Config($this->form))->toArray();
		$formOptions = $this->additionalOptions + $formOptions;
		$formOptions['data'] = $this->additionalFormOptions + $formOptions['data'];

		$parameter = [
			'form' => $formOptions,
			'resources' => [
				'app' => Form\App::instance()->getEmbeddedFileUrl(),
				'polyfill' => Form\Polyfill::instance()->getEmbeddedFileUrl(),
			]
		];

		return $parameter;
	}

	public function setAdditionalOptions(array $options = [])
	{
		$this->additionalOptions = $options;
		return $this;
	}

	public function setAdditionalFormOptions(array $options = [])
	{
		$this->additionalFormOptions = $options;
		return $this;
	}

	public function configureFormEmbeddedScript(array $options = [])
	{
		// script parameters
		$this->skipMoving = true;
		$this->tagAttributes['data-b24-form'] = join('/', [
			$options['action'] ?: 'inline',
			$this->getId(),
			$options['sec']
		]);
		if (isset($options['telemetrySign']) && is_string($options['telemetrySign']))
		{
			$this->tagAttributes['data-b24-form-telemetry-sign'] = $options['telemetrySign'];
		}

		return $this;
	}

	/**
	 * @return bool
	 */
	public function build()
	{
		$result = parent::build();
		WebForm\Form::cleanCacheByTag($this->getId());
		return $result;
	}

	/**
	 * Removes the package of the form: its file, then the registry entry pointing at it.
	 * Both form delete paths call it. A form that was never built has no entry, which is not a failure.
	 */
	public function delete(): Result
	{
		$primary = ['ENTITY_TYPE' => static::$type, 'ENTITY_ID' => $this->getId()];
		$row = Internals\WebpackTable::getRow(['select' => ['FILE_ID'], 'filter' => $primary]);
		if ($row === null)
		{
			return new Result();
		}

		// Through the file storage rather than off the disk: content shared with other files is
		// reference-counted, so the content, and while referenced even the b_file row, may stay.
		// The file goes first, so a failed removal of the entry leaves it naming the file.
		$fileId = (int)($row['FILE_ID'] ?? 0);
		if ($fileId > 0)
		{
			\CFile::Delete($fileId);
		}

		return Internals\WebpackTable::delete($primary);
	}
}
