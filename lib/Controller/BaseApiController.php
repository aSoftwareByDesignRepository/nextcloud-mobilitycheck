<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

abstract class BaseApiController extends Controller
{
	public function __construct(string $appName, IRequest $request)
	{
		parent::__construct($appName, $request);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function payload(): array
	{
		$params = $this->request->getParams();
		if (!is_array($params)) {
			return [];
		}
		unset($params['route'], $params['_route'], $params['_route_params'], $params['requesttoken']);
		return $params;
	}

	protected function ok(mixed $data = null): DataResponse
	{
		return new DataResponse([
			'ok' => true,
			'data' => $data,
		]);
	}

	/**
	 * @template T
	 * @param callable():T $fn
	 */
	protected function wrap(callable $fn): DataResponse
	{
		try {
			return $this->ok($fn());
		} catch (\Throwable $e) {
			return ApiJsonErrorResponse::fromThrowable($e);
		}
	}
}
