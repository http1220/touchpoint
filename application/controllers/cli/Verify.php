<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Channel\CurlHttpClient;
use App\Verify\Ga4Reader;
use App\Verify\GoogleServiceAccount;
use App\Verify\Reconciliation;

/**
 * 매체에 보낸 것을 되읽어 대조한다. CLI 전용.
 *
 *   php public/index.php cli/verify events              이벤트 이름별 건수만
 *   php public/index.php cli/verify ga4                 오늘 보낸 것 대조
 *   php public/index.php cli/verify ga4 2026-09-13 today
 *
 * **왜 이게 있어야 하는가.** MP 운영 엔드포인트는 페이로드가 틀려도 204 다.
 * 전송 로그의 "성공률 100%" 는 *도달* 만 말하고 *집계* 는 말하지 못한다.
 * 되읽어 맞대야 그 둘이 갈린다 → docs/benchmarks.md 5장
 *
 * 필요한 설정 셋 (`.env`)
 *
 *   GA4_PROPERTY_ID        숫자. 측정 ID(G-…) 가 아니다
 *   GA4_SA_KEY_FILE        서비스 계정 JSON 키 경로
 *   (그리고 그 서비스 계정을 GA4 속성에 **뷰어로 추가**해야 한다)
 */
class Verify extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();

        if ( ! is_cli())
        {
            show_404();
        }
    }

    /**
     * 이 서비스 계정이 볼 수 있는 속성 목록.
     *
     * "403 도 아니고 오류도 없는데 0건" 일 때 쓴다. 그 상태에서 남는
     * 의심은 엉뚱한 속성을 보고 있다는 것뿐이다.
     */
    public function props()
    {
        $reader = $this->reader();

        if ($reader === NULL)
        {
            return;
        }

        try
        {
            $props = $reader->propertySummaries();
        }
        catch (Exception $e)
        {
            $this->fail($e->getMessage());

            return;
        }

        if ($props === array())
        {
            $this->line('이 서비스 계정이 볼 수 있는 속성이 없습니다.');
            $this->line('  GA4 관리 > 속성 액세스 관리에서 뷰어로 추가됐는지 확인하세요.');

            return;
        }

        $current = trim((string) (getenv('GA4_PROPERTY_ID') ?: ''));

        $this->line('볼 수 있는 속성');

        foreach ($props as $p)
        {
            $this->line(sprintf(
                '  %-14s %-28s (계정: %s)%s',
                $p['property'], $p['displayName'], $p['account'],
                $p['property'] === $current ? '  ← 지금 .env 의 값' : ''
            ));
        }

        if ($current !== '' && ! in_array($current, array_column($props, 'property'), TRUE))
        {
            $this->line('');
            $this->line('  ⚠ .env 의 GA4_PROPERTY_ID='.$current.' 는 위 목록에 없습니다.');
        }
    }

    /** 속성에 무엇이 들어와 있는지부터 본다. 권한·설정 확인용. */
    public function events($start = 'today', $end = 'today')
    {
        $reader = $this->reader();

        if ($reader === NULL)
        {
            return;
        }

        try
        {
            $counts = $reader->eventCounts($start, $end);
        }
        catch (Exception $e)
        {
            $this->fail($e->getMessage());

            return;
        }

        if ($counts === array())
        {
            $this->line('이벤트가 없습니다. ('.$start.' ~ '.$end.')');
            $this->line('');
            $this->line('  표준 보고서는 처리 지연이 있습니다. 방금 보낸 것은 아직 안 보일 수 있습니다.');

            return;
        }

        $this->line('이벤트 ('.$start.' ~ '.$end.')');

        foreach ($counts as $name => $n)
        {
            $this->line(sprintf('  %-24s %7d', $name, $n));
        }
    }

    /** 우리 기록과 매체 기록을 한 건씩 맞댄다. */
    public function ga4($start = 'today', $end = 'today')
    {
        $reader = $this->reader();

        if ($reader === NULL)
        {
            return;
        }

        $sent = $this->sentTransactionIds($start, $end);

        if ($sent === array())
        {
            $this->line('그 기간에 ga4 로 sent 처리된 전환이 없습니다.');

            return;
        }

        try
        {
            $observed = $reader->transactionIds($start, $end);
        }
        catch (Exception $e)
        {
            $this->fail($e->getMessage());

            return;
        }

        $r = Reconciliation::of($sent, $observed);

        $this->line('대조 ('.$start.' ~ '.$end.')');
        $this->line(sprintf('  우리가 보낸 것      %6d', $r->sentCount));
        $this->line(sprintf('  매체가 집계한 것    %6d', $r->observedCount));
        $this->line('');
        $this->line(sprintf('  일치                %6d', count($r->matched)));
        $this->line(sprintf('  누락(보냈는데 없음) %6d', count($r->missing)));
        $this->line(sprintf('  초과(안 보냈는데)   %6d', count($r->unexpected)));
        $this->line(sprintf('  중복(두 번 세어짐)  %6d', count($r->duplicated)));
        $this->line('');
        $this->line(sprintf('  **반영률 %.1f%%**  (전송 성공률과 다른 숫자다)', $r->reflectionRate() * 100));

        /*
         * 관측된 것이 적으면 통째로 찍는다.
         *
         * 반영률이 낮게 나왔을 때 알아야 하는 것은 "몇 개가 빠졌나" 가 아니라
         * **"들어간 것은 무엇이었나"** 다. 그게 원인을 가른다 — 들어간 것이
         * 전부 특정 시각 이전이면 처리 지연이고, 특정 표식이 없는 것뿐이면
         * 필터이며, 뒤죽박죽이면 진짜 유실이다.
         */
        if ($r->observedCount > 0 && $r->observedCount <= 50)
        {
            $this->line('');
            $this->line('  매체에 들어간 것 (전부):');

            foreach ($observed as $id => $n)
            {
                $this->line(sprintf(
                    '    %s  %d회%s',
                    $id, $n,
                    in_array($id, $r->matched, TRUE) ? '' : '  ← 우리가 보낸 것이 아니다'
                ));
            }
        }

        foreach (array_slice($r->missing, 0, 5) as $id)
        {
            $this->line('    누락 예: '.$id);
        }

        /*
         * 누락이 많으면 목록을 파일로 뺀다.
         *
         * 화면에 다 찍으면 읽을 수 없고, 몇 개만 찍으면 **어느 무리가
         * 빠졌는지** 알 수 없다. 누락은 대개 뭉쳐서 생기므로(같은 시각,
         * 같은 배치) 전부 받아 DB 와 조인해 봐야 원인이 보인다.
         */
        if ($r->missing !== array())
        {
            $path = '/tmp/verify-missing.txt';

            if (@file_put_contents($path, implode(PHP_EOL, $r->missing).PHP_EOL) !== FALSE)
            {
                $this->line('');
                $this->line('  누락 '.count($r->missing).'건 전체 → '.$path);
            }
        }

        foreach (array_slice($r->duplicated, 0, 5, TRUE) as $id => $n)
        {
            $this->line('    중복 예: '.$id.' ('.$n.'회)');
        }

        if ( ! $r->isClean())
        {
            $this->line('');
            $this->line('  누락이 있다면 204 를 받고도 버려진 전송이 있다는 뜻입니다.');
            $this->line('  처리 지연일 수도 있으니 몇 시간 뒤 다시 돌려 보세요.');
        }
    }

    // ────────────────────────────────────────────────────────

    /**
     * 그 기간에 ga4 채널로 `sent` 처리된 전환의 transaction_id.
     *
     * `transaction_id` 로 싣는 값이 `conversion_uid` 다 → src/Channel/Ga4Channel
     *
     * @return list<string>
     */
    private function sentTransactionIds($start, $end)
    {
        $from = ($start === 'today') ? date('Y-m-d') : $start;
        $to   = ($end === 'today') ? date('Y-m-d') : $end;

        /*
         * **검증 엔드포인트로 나간 것은 뺀다.**
         *
         * `GA4_DEBUG=true` 면 `/debug/mp/collect` 로 간다. 거기는 페이로드를
         * 검사만 하고 **적재하지 않는다.** 그런데 응답이 성공이라 아웃박스는
         * `sent` 로 적는다 — 대조하면 영원히 `missing` 이다.
         *
         * 실제로 그 5건 때문에 "204 를 받고도 버려졌다" 로 읽을 뻔했다.
         * 알고 보니 애초에 적재 대상이 아니었다.
         *
         * 가르는 값은 HTTP 상태다. 실측으로 확인했다 — 운영은 `204`,
         * 검증은 `200` 이다(→ docs/benchmarks.md 3-1). 상태로 가르는 것이
         * 암묵적이긴 하나, 지금 스키마에 "어느 엔드포인트로 갔는가" 를
         * 남기는 자리가 없다. 남기는 편이 낫다 → 아래 주석
         */
        $rows = $this->db->query(
            'SELECT LOWER(HEX(c.conversion_uid)) AS uid
               FROM dispatch_outbox o
               JOIN conversions c ON c.id = o.conversion_id
              WHERE o.channel = ? AND o.status = ?
                AND DATE(o.sent_at) BETWEEN ? AND ?
                AND EXISTS (
                      SELECT 1 FROM dispatch_log l
                       WHERE l.outbox_id = o.id AND l.http_status = 204
                    )',
            array('ga4', 'sent', $from, $to)
        )->result_array();

        return array_column($rows, 'uid');
    }

    /** @return Ga4Reader|null */
    private function reader()
    {
        $property = trim((string) (getenv('GA4_PROPERTY_ID') ?: ''));
        $keyFile  = trim((string) (getenv('GA4_SA_KEY_FILE') ?: ''));

        if ($property === '' OR $keyFile === '')
        {
            $this->fail(
                ".env 에 GA4_PROPERTY_ID 와 GA4_SA_KEY_FILE 이 필요합니다.\n".
                "  GA4_PROPERTY_ID  관리 > 속성 설정 의 숫자 ID (측정 ID G-… 가 아닙니다)\n".
                "  GA4_SA_KEY_FILE  서비스 계정 JSON 키 경로\n".
                "  그리고 그 서비스 계정 이메일을 GA4 속성에 뷰어로 추가해야 합니다."
            );

            return NULL;
        }

        try
        {
            $http = new CurlHttpClient('touchpoint-verify/1.0');

            return new Ga4Reader(
                $http,
                GoogleServiceAccount::fromKeyFile($http, $keyFile, Ga4Reader::SCOPE),
                $property
            );
        }
        catch (Exception $e)
        {
            $this->fail($e->getMessage());

            return NULL;
        }
    }

    private function fail($msg)
    {
        fwrite(STDERR, $msg.PHP_EOL);
    }

    private function line($msg)
    {
        fwrite(STDOUT, $msg.PHP_EOL);
    }
}
