<?php


namespace Bitrix\Sale\Exchange\Integration\Controller;


use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Sale\Exchange\Integration;

class Scenarios extends Controller
{
	public function activityAddsFromOrderListAction(array $params)
	{
		$scenario = new Integration\Service\Scenarios\Controller\ActivityAdd();
		return $this
			->toArray(
				$scenario->adds($params));
	}

	public function resolveContactFieldsValuesFromOrderListAction(array $params)
	{
		return ['result' => (new Integration\Service\Scenarios\RefreshClient\Contact())
			->resolve($params)];
	}
	public function resolveUserTypeIAfterComparingRemotelyRelationFromOrderListAction(array $params)
	{
		//$result == 0 if all dependents exists

		$contacts = (new Integration\Service\Scenarios\RefreshClient\Contact())
			->diff($params)
			->toArray();

		return ['result' => $contacts];
	}
	public function contactAddsFromOrderListAction(array $params)
	{
		$scenario = new Integration\Service\Scenarios\RefreshClient\Contact();
		return $this
			->toArray(
				$scenario->adds($params)
			);
	}

	public function dealContactItemsGetAction($id)
	{
		try
		{
			return ['result' => $this->createRefreshClientsDealContact()->itemsGet($id)];
		}
		catch (Integration\Exception\BatchableException $exception)
		{
			return $this->handleDealContactException($exception);
		}
	}
	public function dealContactUpdatesAction($id, array $items, array $contacts)
	{
		try
		{
			return ['result' => $this->createRefreshClientsDealContact()->updates($id, $items, $contacts)];
		}
		catch (Integration\Exception\BatchableException $exception)
		{
			return $this->handleDealContactException($exception);
		}
	}
	public function dealContactAddsAction($id, array $items)
	{
		try
		{
			return ['result' => $this->createRefreshClientsDealContact()->adds($id, $items)];
		}
		catch (Integration\Exception\BatchableException $exception)
		{
			return $this->handleDealContactException($exception);
		}
	}

	private function handleDealContactException(Integration\Exception\BatchableException $exception): ?array
	{
		if ($exception->getCode() !== Integration\Rest\Cmd\Base::ERROR_TOKEN_NOT_FOUND)
		{
			throw $exception;
		}

		$this->addError(new Error($exception->getMessage(), 'INTEGRATION_NOT_CONFIGURED'));

		return null;
	}

	protected function createRefreshClientsDealContact(): Integration\Service\Scenarios\RefreshClientsDeal\Contact
	{
		return new Integration\Service\Scenarios\RefreshClientsDeal\Contact();
	}

	protected function toArray(Integration\Service\Internal\Container\Collection $collection)
	{
		$result = [];
		/** @var Integration\Service\Internal\Container\Item $item */
		foreach ($collection as $item)
		{
			$result[$item->getInternalIndex()]= [
				'result'=>$item->getEntity()->getFieldsValues(),
				'result_error'=>$item->hasError() ? $item->getError()->getMessage(): '',
			];
		}
		return $result;
	}
}
