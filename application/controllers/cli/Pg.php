<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Payment\PaymentStatus;
use App\Payment\WebhookSignature;

/**
 * 스텁 PG. CLI 전용. **측정 장비다.**
 *
 *   php public/index.php cli/pg sign <payment_uid> <status>   서명된 본문을 파일로
 *   php public/index.php cli/pg send <payment_uid> <status>   단건 전송
 *
 * 실제 PG 연동이 아니다 → ADR-008. 이것은 **D-3 의 두 줄을 재기 위한
 * 도구**이고, 그래서 `sign` 과 `send` 가 갈려 있다.
 *
 * ── 왜 sign 이 따로 있는가 ──
 *
 * 동시성을 PHP 루프로 만들면 안 된다. D-3 의 동시 8개를 잰 방식이
 * `xargs -P8` 이었고, **같은 방식이어야 기존 결과와 비교된다.**
 *
 *   php public/index.php cli/pg sign <uid> captured > /tmp/hook.env
 *   . /tmp/hook.env
 *   seq 1 8 | xargs -P8 -I{} curl -s -H "$SIG" --data-binary @$BODY_FILE <URL>
 *
 * 그리고 `sign` 이 뱉은 본문을 그대로 여러 번 보내면 **바이트까지 동일한
 * 재전송**이 된다. PG 재전송이 실제로 그런 모양이므로, 가장 정직한
 * 중복 시험이다 — 본문을 매번 새로 만들면 `event_id` 가 달라져
 * "같은 이벤트의 재전송" 이 아니라 "다른 이벤트 여덟 개" 가 된다.
 */
class Pg extends MY_Controller
{
	/** `sign` 이 본문을 떨구는 곳. 인자로 받지 않는 이유는 sign() 주석에 있다. */
	const BODY_PATH = '/tmp/pg-body.json';

	public function __construct()
	{
		parent::__construct();

		if ( ! is_cli())
		{
			show_404();
		}
	}

	/**
	 * 서명된 본문과 헤더를 만들어 **셸이 쓸 수 있는 형태로** 내보낸다.
	 *
	 * 출력은 `eval`/`source` 할 수 있는 셸 변수다. 전송은 하지 않는다 —
	 * 전송하는 쪽이 셸이어야 동시성을 셸이 만든다.
	 */
	public function sign($uidHex = NULL, $status = NULL)
	{
		/*
		 * 출력 경로를 인자로 받지 않는다.
		 *
		 * CI3 의 CLI 인자는 **URI 세그먼트**라 `/` 에서 쪼개진다.
		 * `cli/pg sign <uid> captured /tmp/body.json` 을 주면 마지막 인자가
		 * `tmp` 가 되고 파일이 엉뚱한 데 떨어진다 — 실제로 그렇게 당했고,
		 * 서명은 맞는데 본문이 비어 401 여덟 개를 받았다. 경로를 고정한다.
		 */
		$path = self::BODY_PATH;

		list($uidHex, $status) = $this->args($uidHex, $status);

		$body = $this->payload($uidHex, $status);
		$now  = time();
		$sig  = $this->signer()->header($body, $now);

		if (@file_put_contents($path, $body) === FALSE)
		{
			fwrite(STDERR, '본문을 쓸 수 없습니다: '.$path.PHP_EOL);
			exit(1);
		}

		// 작은따옴표로 감싼다 — 헤더에 쉼표·등호가 들어 있다.
		$this->line('BODY_FILE='.escapeshellarg($path));
		$this->line('SIG='.escapeshellarg(WebhookSignature::HEADER.': '.$sig));
		$this->line('URL='.escapeshellarg($this->url()));
		$this->line('# . 이 파일을 source 한 뒤:');
		$this->line('#   seq 1 8 | xargs -P8 -I{} curl -s -o /dev/null -w "%{http_code} " \\');
		$this->line('#     -H "$SIG" -H "Content-Type: application/json" \\');
		$this->line('#     --data-binary @$BODY_FILE "$URL"');
	}

	/** 단건 전송. 순서 역전 시험처럼 **순서가 중요한** 경우에 쓴다. */
	public function send($uidHex = NULL, $status = NULL)
	{
		list($uidHex, $status) = $this->args($uidHex, $status);

		$body = $this->payload($uidHex, $status);
		$sig  = $this->signer()->header($body, time());

		$res = (new App\Channel\CurlHttpClient('touchpoint-stub-pg/1.0'))->postJson(
			$this->url(),
			$body,
			array(WebhookSignature::HEADER => $sig),
			10000
		);

		$this->line(sprintf('%s → %s %s',
			$status,
			$res->status === NULL ? '실패' : $res->status,
			$res->status === NULL ? (string) $res->error : trim($res->body)
		));

		// 셸에서 분기할 수 있게. 2xx 가 아니면 0 이 아닌 코드로 끝낸다.
		exit($res->isSuccess() ? 0 : 1);
	}

	// ────────────────────────────────────────────────────────

	/**
	 * 웹훅 본문.
	 *
	 * `event_id` 는 **호출마다 새로 만든다.** 같은 이벤트의 재전송을
	 * 흉내 내려면 `sign` 이 만든 파일을 재사용해야 한다 — 클래스 주석 참조.
	 */
	private function payload($uidHex, $status)
	{
		return (string) json_encode(array(
			'event_id'     => 'evt_'.bin2hex(tp_uuid7()),
			'payment_uid'  => $uidHex,
			'status'       => $status,

			/*
			 * 금액을 싣지만 **서버는 이것을 쓰지 않는다.** 금액의 진실은
			 * 우리가 만든 payments 행이다 → 계획 6장. 실제 PG 도 금액을
			 * 보내므로 모양만 맞춘다.
			 */
			'amount_minor' => 9900,
			'currency'     => 'KRW',
			'occurred_at'  => gmdate('Y-m-d\TH:i:s').'.000Z',
		), JSON_UNESCAPED_SLASHES);
	}

	private function signer()
	{
		$secret = (string) (getenv('PG_WEBHOOK_SECRET') ?: '');

		if ($secret === '')
		{
			fwrite(STDERR, '.env 의 PG_WEBHOOK_SECRET 이 비어 있습니다.'.PHP_EOL);
			exit(1);
		}

		return new WebhookSignature($secret, (int) (getenv('PG_WEBHOOK_TOLERANCE_SEC') ?: 300));
	}

	private function url()
	{
		$host = getenv('SHOP_DOMAIN') ?: '';

		return $host === '' ? 'http://localhost/webhooks/pg' : 'https://app.'.$host.'/webhooks/pg';
	}

	private function args($uidHex, $status)
	{
		$uidHex = strtolower(trim((string) $uidHex));
		$status = strtolower(trim((string) $status));

		if (preg_match('/\A[0-9a-f]{32}\z/', $uidHex) !== 1)
		{
			fwrite(STDERR, 'payment_uid 는 32자 hex 여야 합니다.'.PHP_EOL);
			exit(1);
		}

		if ( ! in_array($status, PaymentStatus::ALL, TRUE))
		{
			fwrite(STDERR, 'status 는 '.implode(' | ', PaymentStatus::ALL).' 중 하나여야 합니다.'.PHP_EOL);
			exit(1);
		}

		return array($uidHex, $status);
	}

	private function line($msg)
	{
		fwrite(STDOUT, $msg.PHP_EOL);
	}
}
