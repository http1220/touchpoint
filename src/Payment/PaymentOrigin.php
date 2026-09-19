<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * 결제가 어디서 왔는가 — 멱등 키 접두어로 가른다. 결제 이력 화면(/pay/history)이 쓴다.
 *
 * **자기 신고다.** 키는 요청자가 정하고 `/purchase`(스텁)는 인증이 없다. 누구나
 * `interview-` 로 시작하는 키를 보내 "면접 시연 대본" 으로 보이게 할 수 있다.
 * 화면에도 그렇게 적는다. 키 원문은 화면에 내지 않는다 — 남이 정한 문자열이다.
 *
 * 접두어와 출처는 운영에 남은 행을 보고 맞췄다(09-20). 기록에서 출처를 찾지
 * 못한 접두어는 억지로 이름 붙이지 않고 OTHER 로 둔다.
 */
final class PaymentOrigin
{
    public const OTHER = '기타 확인';

    /** 구분자(-)까지 본다 — `ctl2-…` 는 `ctl-` 에 걸리지 않는다 */
    private const PREFIXES = [
        'demo-'      => ['카드 없는 버튼', true],    // views/pay/index.php ① — 방문자
        'pay-'       => ['카드 결제창', true],       // views/pay/index.php ② — 방문자
        'interview-' => ['면접 시연 대본', false],   // 저장소 밖 대본, 09-19
        'd3-'        => ['동시성 측정 (D-3)', false], // failure-scenarios D-3, 09-14
        'ctl-'       => ['동시성 측정 (D-3)', false], // 같은 측정의 대조군
        'ctl2-'      => ['동시성 측정 (D-3)', false],
        'rf-'        => ['환불 시험', false],         // worklog 09-15 「환불 웹훅」
        'e2e-'       => ['매체 전송 확인', false],    // ADR-005 Meta 검증, 09-14
        'e2e2-'      => ['매체 전송 확인', false],
        'verify-'    => ['배포 확인', false],         // worklog 09-19 스모크
        'it-'        => ['통합 테스트', false],       // tests/integration — 운영에는 없다
    ];

    public static function label(string $idempotencyKey): string
    {
        return self::match($idempotencyKey)[0] ?? self::OTHER;
    }

    /** 시연 화면의 두 버튼으로 만든 결제인가. 아니면 운영자가 남긴 확인·측정 행이다 */
    public static function byVisitor(string $idempotencyKey): bool
    {
        return self::match($idempotencyKey)[1] ?? false;
    }

    /** @return array{0: string, 1: bool}|array{} */
    private static function match(string $key): array
    {
        foreach (self::PREFIXES as $prefix => $origin) {
            if (str_starts_with($key, $prefix)) {
                return $origin;
            }
        }

        return [];
    }
}
