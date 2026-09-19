<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 결제가 어디서 왔는가 — 멱등 키의 **모양**으로 가른다. 결제 이력 화면(/pay/history)이 쓴다.
 *
 * **자기 신고다.** 키는 요청자가 정하고 `/purchase`(스텁)는 인증이 없다. 누구나
 * `interview-` 로 시작하는 키를 보내 "면접 시연 대본" 으로 보이게 할 수 있다.
 * 화면에도 그렇게 적는다. 키 원문은 화면에 내지 않는다 — 남이 정한 문자열이다.
 *
 * ── 접두어만으로는 틀렸다 (09-20) ──
 *
 * 처음엔 접두어만 봤다. 운영 화면을 찍어 보니 09-17 행이 "카드 없는 버튼" 으로
 * 떴는데, 그 버튼은 09-19 에 생겼다. `demo-` 를 먼저 쓴 것은 운영자 스크립트
 * (scripts/demo-cycle.sh, `demo-<유닉스초>`)였다. 버튼은 `demo-<UUID>` 다.
 * 그래서 방문자 버튼 둘은 **접두어 + UUID** 까지 맞아야 방문자로 센다.
 *
 * 모양과 출처는 운영에 남은 행과 저장소의 키 생성 코드를 대조해 맞췄다(09-20).
 * 기록에서 출처를 찾지 못한 모양(`vt-`)은 이름을 지어내지 않고 OTHER 로 둔다.
 */
final class PaymentOrigin
{
    public const OTHER = '기타 확인';

    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** 위에서부터 처음 맞는 것. [정규식, 출처, 방문자인가] */
    private const SHAPES = [
        ['/\Ademo-'.self::UUID.'\z/', '카드 없는 버튼', true],       // views/pay/index.php ①
        ['/\Apay-'.self::UUID.'\z/', '카드 결제창', true],           // views/pay/index.php ②
        ['/\Ademo-\d+\z/', '1사이클 데모 스크립트', false],           // scripts/demo-cycle.sh
        ['/\Ainterview-/', '면접 시연 대본', false],                  // 저장소 밖 대본, 09-19
        ['/\A(?:d3|ctl|ctl2)-/', '동시성 측정 (D-3)', false],        // failure-scenarios D-3 · 대조군, 09-14
        ['/\Arf-/', '환불 시험', false],                             // worklog 09-15 「환불 웹훅」
        ['/\Ae2e2?-/', '매체 전송 확인', false],                     // ADR-005 Meta 검증, 09-14
        ['/\Averify-/', '배포 확인', false],                         // worklog 09-19 스모크
        ['/\Ait-/', '통합 테스트', false],                           // tests/integration — 운영에는 없다
    ];

    public static function label(string $idempotencyKey): string
    {
        return self::match($idempotencyKey)[1] ?? self::OTHER;
    }

    /** 시연 화면의 두 버튼으로 만든 결제인가. 아니면 운영자가 남긴 확인·측정 행이다 */
    public static function byVisitor(string $idempotencyKey): bool
    {
        return self::match($idempotencyKey)[2] ?? false;
    }

    /** @return array{0: string, 1: string, 2: bool}|array{} */
    private static function match(string $key): array
    {
        foreach (self::SHAPES as $shape) {
            if (preg_match($shape[0], $key) === 1) {
                return $shape;
            }
        }

        return [];
    }
}
