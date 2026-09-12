<?php

declare(strict_types=1);

namespace App\Channel;

/**
 * 외부로 나가는 HTTP.
 *
 * 인터페이스로 둔 이유가 하나다 — **테스트에서 갈아끼우기 위해서.**
 * 이게 없으면 GA4 페이로드 매핑을 확인하려고 매번 구글에 실제 요청을
 * 보내게 되고, 그러면 테스트가 네트워크와 남의 서비스 상태에 묶인다.
 *
 * 가짜 구현을 끼우면 **무엇을 보내려 했는지**를 단위 테스트로 못박을 수 있다.
 * 어댑터가 하는 일의 대부분이 "우리 모델 → 매체 규격" 변환이므로,
 * 검증할 가치가 있는 것도 거기다.
 */
interface HttpClient
{
    /**
     * @param string               $url
     * @param string               $json      본문. 이미 직렬화된 JSON
     * @param array<string,string> $headers
     * @param int                  $timeoutMs 응답까지 기다릴 최대 시간
     */
    public function postJson(string $url, string $json, array $headers = [], int $timeoutMs = 3000): HttpResponse;

    /**
     * `application/x-www-form-urlencoded` 로 보낸다.
     *
     * JSON 이 표준인 시대에 이게 왜 필요한가 — **OAuth2 토큰 엔드포인트가
     * 폼 인코딩만 받는다**(RFC 6749 §4.1.3). 매체 API 는 JSON 인데 그 API 를
     * 쓰기 위한 인증은 폼이다. 하나로 뭉뚱그릴 수 없어서 메서드를 나눈다.
     *
     * @param array<string,string> $fields
     */
    public function postForm(string $url, array $fields, int $timeoutMs = 3000): HttpResponse;
}
