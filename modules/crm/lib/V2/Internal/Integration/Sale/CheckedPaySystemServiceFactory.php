<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Sale;

use Bitrix\Crm\V2\Internal\Entity\PaySystem\ResolvedHandler;
use Bitrix\Crm\V2\Internal\Service\PaySystem\HandlerCatalog;
use Bitrix\Crm\V2\Internal\Service\PaySystem\HandlerGuard;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem;

final class CheckedPaySystemServiceFactory
{
	public function __construct(private readonly HandlerGuard $guard = new HandlerGuard())
	{
	}

	public function createFromFields(array $fields, string $purpose, ?Payment $payment = null): Result
	{
		if (!Loader::includeModule('sale'))
		{
			return $this->deny();
		}

		$context = $this->getContext();
		if ($payment !== null && $payment->getId() > 0)
		{
			$context['paymentId'] = $payment->getId();
		}
		if (isset($fields['ID']) && (int)$fields['ID'] > 0)
		{
			$context['paySystemId'] = (int)$fields['ID'];
		}
		$result = $this->guard->resolve($fields, $purpose, $context);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $this->create($result->getData()['handler'], $payment);
	}

	public function create(ResolvedHandler $handler, ?Payment $payment = null): Result
	{
		if (!Loader::includeModule('sale') || !$this->matchesContext($handler, $payment))
		{
			return $this->deny();
		}

		$resolved = $this->guard->resolve($handler->getFields(), $handler->getPurpose(), $handler->getContext());
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}
		$actual = $resolved->getData()['handler'];
		if (
			$actual->getFiles() !== $handler->getFiles()
			|| $actual->getCode() !== $handler->getCode()
			|| $actual->getHandlerType() !== $handler->getHandlerType()
			|| $actual->getClassName() !== $handler->getClassName()
			|| $actual->getServiceFields() !== $handler->getServiceFields()
			|| $actual->getPurpose() !== $handler->getPurpose()
		)
		{
			return $this->deny();
		}

		$plans = $this->getDependencyPlans($handler);
		if ($plans === null)
		{
			return $this->deny();
		}
		foreach ($plans as $plan)
		{
			if (!$this->validateSelection($plan))
			{
				return $this->deny();
			}
		}

		try
		{
			foreach ($plans as $plan)
			{
				$className = $plan->getClassName();
				if (!class_exists($className, false))
				{
					require_once $plan->getFiles()['handler'];
				}
				if (!$this->hasExpectedOrigin($className, $plan->getFiles()['handler']))
				{
					return $this->deny();
				}
			}
			foreach ($plans as $plan)
			{
				if (!$this->validateSelection($plan))
				{
					return $this->deny();
				}
			}
			if (!$this->matchesContext($handler, $payment))
			{
				return $this->deny();
			}

			$service = new CheckedPaySystemService($handler->getServiceFields());
		}
		catch (\Throwable)
		{
			return $this->deny();
		}

		return (new Result())->setData(['service' => $service, 'handler' => $handler]);
	}

	private function getDependencyPlans(ResolvedHandler $handler): ?array
	{
		if ($handler->getClassName() === null)
		{
			return null;
		}
		$code = $handler->getCode();
		$dependency = null;
		if (in_array($code, ['billbr', 'billby', 'billde', 'billen', 'billfr', 'billkz', 'billla', 'billua'], true))
		{
			$dependency = 'bill';
		}
		elseif ($code === 'invoicedocument')
		{
			$dependency = 'orderdocument';
		}

		$plans = [];
		if ($dependency !== null)
		{
			$result = $this->guard->resolve(
				['ACTION_FILE' => $dependency], HandlerCatalog::PURPOSE_METADATA, $handler->getContext(),
			);
			if (!$result->isSuccess())
			{
				return null;
			}
			$plans[] = $result->getData()['handler'];
		}
		$plans[] = $handler;

		return $plans;
	}

	private function validateSelection(ResolvedHandler $handler): bool
	{
		$action = $handler->getServiceFields()['ACTION_FILE'];
		$folder = PaySystem\Manager::getFolderFromClassName($action);
		$root = Application::getDocumentRoot();
		$isRest = $handler->getClassName() === PaySystem\RestHandler::class;
		$selectedType = null;
		foreach (PaySystem\Manager::getHandlerDirectories() as $type => $directory)
		{
			$file = $root . $directory . $folder . '/handler.php';
			if (is_file($file))
			{
				if ($isRest || $type !== $handler->getHandlerType() || realpath($file) !== $handler->getFiles()['handler'])
				{
					return false;
				}
				$selectedType = $type;
				break;
			}
		}

		if ($isRest)
		{
			if (
				!in_array($handler->getPurpose(), [HandlerCatalog::PURPOSE_PAYMENT, HandlerCatalog::PURPOSE_METADATA], true)
				|| !PaySystem\Manager::isRestHandler($action)
				|| (class_exists($action, false) && !$this->hasExpectedOrigin($action, $handler->getFiles()['handler']))
			)
			{
				return false;
			}
		}
		elseif (
			$selectedType === null
			|| strcasecmp(PaySystem\Manager::getClassNameFromPath($action), $handler->getClassName()) !== 0
		)
		{
			return false;
		}

		$className = $handler->getClassName();
		if (class_exists($className, false) && !$this->hasExpectedOrigin($className, $handler->getFiles()['handler']))
		{
			return false;
		}

		return $this->validateTemplates($handler);
	}

	private function validateTemplates(ResolvedHandler $handler): bool
	{
		$files = $handler->getFiles();
		if (!isset($files['template'], $files['template_pdf']))
		{
			return true;
		}

		$siteTemplate = \CSite::GetCurTemplate();
		$templates = $siteTemplate === '.default' ? [$siteTemplate] : [$siteTemplate, '.default'];
		foreach (['template', 'template_pdf'] as $role)
		{
			foreach (['/local/templates/', '/bitrix/templates/'] as $base)
			{
				foreach ($templates as $template)
				{
					$file = Application::getDocumentRoot() . $base . $template
						. '/payment/' . $handler->getCode() . '/template/' . $role . '.php'
					;
					if (is_file($file))
					{
						if (realpath($file) !== $files[$role])
						{
							return false;
						}
						continue 3;
					}
				}
			}
		}

		return true;
	}

	private function hasExpectedOrigin(string $className, string $file): bool
	{
		if (!class_exists($className, false))
		{
			return false;
		}
		$source = (new \ReflectionClass($className))->getFileName();

		return is_string($source) && realpath($source) === $file;
	}

	private function getContext(): array
	{
		return [
			'documentRoot' => rtrim(Application::getDocumentRoot(), '/\\'),
			'handlerDirectories' => PaySystem\Manager::getHandlerDirectories(),
			'siteTemplate' => \CSite::GetCurTemplate(),
		];
	}

	private function matchesContext(ResolvedHandler $handler, ?Payment $payment): bool
	{
		$context = $handler->getContext();
		foreach ($this->getContext() as $key => $value)
		{
			if (($context[$key] ?? null) !== $value)
			{
				return false;
			}
		}

		$paySystemId = (int)($handler->getFields()['ID'] ?? 0);

		return $handler->getPaymentId() === ($payment !== null && $payment->getId() > 0 ? $payment->getId() : null)
			&& $handler->getPaySystemId() === ($paySystemId > 0 ? $paySystemId : null)
		;
	}

	private function deny(): Result
	{
		return (new Result())->addError(new Error('Payment system handler is not allowed.', HandlerGuard::ERROR_NOT_ALLOWED));
	}
}
