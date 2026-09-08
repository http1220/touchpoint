-- 읽기 복제본 배정
--
-- 대상 조직에서 관측한 동작을 그대로 구현한다.
--   쿠키 없음  → 복제본 하나를 배정하고 쿠키를 내려준다
--   쿠키 있음  → 그 복제본에 고정한다 (재발급하지 않는다)
--
-- 관측 근거: 쿠키 없이 8회 요청 시 sdb1~sdb5가 분산 배정됐고,
--            쿠키를 실어 보내면 Set-Cookie가 나오지 않았다.
--
-- 이 방식의 한계는 의도적으로 남겨둔다.
--   기기·브라우저를 바꾸면 다른 복제본에 배정되므로
--   "방금 산 코인이 안 보인다"가 재발한다.
--   docs/failure-scenarios.md E-1 에서 이걸 재현한다.

local _M = {}

-- 읽기 대상. rdb1은 프라이머리를 읽기 전용으로 쓰고, rdb2는 실제 복제본이다.
-- 지연이 서로 달라서 배정 결과에 따라 사용자가 보는 신선도가 갈린다.
local READ_TARGETS = { "rdb1", "rdb2" }

local COOKIE_NAME = "ab_rdb"

local function is_known(name)
    for _, v in ipairs(READ_TARGETS) do
        if v == name then return true end
    end
    return false
end

-- 배정은 요청 ID에서 결정한다. 공유 카운터를 쓰지 않는다.
--
-- 왜 카운터를 버렸나
--
--   try_files가 /index.php로 내부 리다이렉트하면 nginx는 위치를 다시 찾고
--   access 단계를 한 번 더 실행한다. 카운터라면 요청 하나가 2씩 올리므로
--   (n % 2)가 언제나 같은 값이 되어 한쪽 복제본으로만 간다.
--   실제로 그랬다 — 쿠키 없는 요청 12번이 전부 rdb1이었다.
--
--   "이미 배정됐으면 건너뛴다"로 막으려 했지만 안 된다.
--   서버 블록의 set $read_target "" 도 내부 리다이렉트에서 다시 실행되어
--   플래그가 매번 지워지기 때문이다.
--
--   $request_id는 요청당 한 번 생성되고 내부 리다이렉트를 넘어 유지된다.
--   그래서 두 번 실행돼도 답이 같다. 공유 메모리 쓰기도 사라진다.
--   엄밀한 라운드로빈은 아니지만, 필요한 성질은 "고르게 갈린다"뿐이다.
local function pick(request_id)
    local sum = 0
    for i = 1, #request_id do
        sum = sum + request_id:byte(i)
    end
    return READ_TARGETS[(sum % #READ_TARGETS) + 1]
end

function _M.assign()
    local cookie = ngx.var["cookie_" .. COOKIE_NAME]
    local target

    if cookie and cookie ~= "" and is_known(cookie) then
        -- 고정. Set-Cookie를 내리지 않는다 — 관측된 동작과 같다.
        target = cookie
    else
        target = pick(ngx.var.request_id or "")
        -- 세션 성격이라 first-party로 충분하다. 크로스사이트로 나갈 이유가 없다.
        ngx.header["Set-Cookie"] = COOKIE_NAME .. "=" .. target ..
            "; Path=/; Max-Age=86400; SameSite=Lax; Secure; HttpOnly"
    end

    -- 앱이 읽기 커넥션을 고를 수 있도록 넘긴다.
    ngx.var.read_target = target
end

return _M
