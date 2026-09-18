<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Channel\CurlHttpClient;
use App\Payment\Gateway\GatewayInterface;
use App\Payment\Gateway\InicisEndpoints;
use App\Payment\Gateway\InicisGateway;

/**
 * PG 조립. CI3 와 `src/Payment/Gateway/` 사이의 다리 — Channels.php 와 같은 자리다.
 *
 * 이름 → 어댑터. 자격 증명이 없으면 만들지 않고 NULL 을 돌려준다. 호출자는
 * NULL 을 "지금 그 PG 로는 결제할 수 없다" 로 받는다(503).
 *
 * 통화 → PG 규칙도 여기 한 곳이다: KRW → inicis, USD → paypal(아직 없음)
 * → docs/plan-multi-pg.md 6장 B1
 */
class Gateways
{
	/** 실PG 이름. `stub` 은 어댑터가 아니라 cli/pg 흉내라 여기 없다 */
	const REAL = array('inicis');

	/** @var array<string, GatewayInterface|null> 지연 생성 */
	private $built = array();

	/** @return GatewayInterface|null */
	public function get($name)
	{
		$name = strtolower((string) $name);

		if ( ! array_key_exists($name, $this->built))
		{
			$this->built[$name] = match ($name) {
				'inicis' => $this->inicis(),
				default  => NULL,
			};
		}

		return $this->built[$name];
	}

	public function isReal($name)
	{
		return in_array(strtolower((string) $name), self::REAL, TRUE);
	}

	/** 결제창 JS 주소를 그리려고 뷰가 묻는다 */
	public function inicisMode()
	{
		$mode = strtolower(trim((string) (getenv('INICIS_MODE') ?: InicisEndpoints::MODE_TEST)));

		return InicisEndpoints::isMode($mode) ? $mode : '';
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 모드와 MID 가 어긋나면 **만들지 않는다** → plan-multi-pg.md D5
	 *
	 * 테스트 키가 운영에 들어가면 결제는 성공하는데 돈이 들어오지 않는다.
	 * 반대면 테스트라고 믿고 실결제가 난다. 어느 쪽이든 설정 사고라, 결제를
	 * 막고 로그 한 줄로 알리는 편이 낫다. scripts/check-env.sh 가 같은 검사를
	 * 배포 전에 한다.
	 */
	private function inicis()
	{
		$mode = $this->inicisMode();
		$mid  = trim((string) (getenv('INICIS_MID') ?: ''));
		$key  = trim((string) (getenv('INICIS_SIGN_KEY') ?: ''));

		if ($mode === '' OR $mid === '' OR $key === '')
		{
			log_message('error', 'gateways: 이니시스 설정(INICIS_MODE · INICIS_MID · INICIS_SIGN_KEY)이 비었거나 모드가 틀려 어댑터를 만들지 않습니다.');

			return NULL;
		}

		$isTestMid = ($mid === 'INIpayTest');

		if (($mode === InicisEndpoints::MODE_TEST) !== $isTestMid)
		{
			log_message('error', sprintf('gateways: 이니시스 모드(%s)와 MID 가 맞지 않습니다 — 테스트 MID 는 test 모드에서만, 그 외 MID 는 live 에서만.', $mode));

			return NULL;
		}

		return new InicisGateway(
			new CurlHttpClient('touchpoint-pay/1.0'),
			$mode,
			$mid,
			$key,
			trim((string) (getenv('INICIS_INIAPI_KEY') ?: '')),
			trim((string) (getenv('INICIS_CLIENT_IP') ?: '')),
			(int) (getenv('INICIS_TIMEOUT_MS') ?: 10000)
		);
	}
}
