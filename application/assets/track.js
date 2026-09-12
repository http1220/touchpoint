/*!
 * touchpoint · track.js
 *
 * 의존성 0. 프레임워크를 쓰지 않는다 — 광고 태그가 바닐라인 이유와 같다.
 * 남의 페이지에 얹히는 코드는 그 페이지가 무엇을 쓰는지 모른다.
 * → docs/decisions/ADR-009-no-spa.md
 *
 * 수집 주소는 주입받지 않고 **자기 script 태그의 src 에서 뽑는다.**
 * 서버가 HTML 에 엔드포인트를 심어 주는 방식은 페이지마다 심는 걸 잊을 수 있고,
 * 스크립트를 다른 호스트로 옮길 때 HTML 까지 고쳐야 한다.
 *
 * 두 전송 경로를 모두 구현해 비교한다 → docs/api-spec.md 3장
 *
 *   fetch + JSON        Content-Type 이 단순 요청이 아니라 preflight 가 뜬다.
 *                       헤더를 붙일 수 있고, 이탈 중에는 취소될 수 있다.
 *   navigator.sendBeacon  text/plain 이라 preflight 가 없다.
 *                       헤더를 못 붙이지만 이탈 중 전송이 보장된다.
 *
 * (fetch 의 keepalive: true 도 세 번째 선택지다. 다만 본문 64KB 제한이 있고
 *  브라우저 지원이 sendBeacon 보다 늦어, 여기서는 대비를 위해 둘만 둔다.)
 */
(function () {
	'use strict';

	var script = document.currentScript;

	if (!script) {
		// currentScript 가 없다 = 비동기 주입이거나 아주 오래된 브라우저.
		// 조용히 죽는 대신 남긴다. 수집이 안 되는 이유를 콘솔에서 찾을 수 있게.
		if (window.console) console.warn('[touchpoint] currentScript 없음 — 초기화 중단');
		return;
	}

	var origin = new URL(script.src, location.href).origin;
	var endpoint = script.dataset.endpoint || (origin + '/collect');
	var workId = script.dataset.work || null;

	/* 방문 식별자.
	 *
	 * ab_vid 쿠키는 HttpOnly 라 자바스크립트가 읽을 수 없다. 일부러 그렇다 —
	 * 스크립트가 읽을 수 있으면 XSS 하나로 방문 이력이 통째로 샌다.
	 *
	 * 그래서 여기서는 URL 의 vid 만 본다. 쿠키는 브라우저가
	 * credentials: 'include' 로 알아서 실어 보내고, 서버가 그걸 읽는다.
	 */
	var vid = new URLSearchParams(location.search).get('vid');

	var queue = [];

	function payload(event, extra) {
		var body = {
			event: event,
			occurred_at: new Date().toISOString()
		};

		if (vid) body.visit_uid = vid;
		if (workId) body.work_id = workId;

		if (extra) {
			for (var k in extra) {
				if (Object.prototype.hasOwnProperty.call(extra, k)) body[k] = extra[k];
			}
		}

		return body;
	}

	/* fetch 경로.
	 *
	 * credentials: 'include' 가 핵심이다. 이게 없으면 쿠키가 안 실리고,
	 * 있으면 서버가 Allow-Origin 을 정확히 반향해야 한다 — * 는 거부된다.
	 * 그 실패를 눈으로 보려고 .env 에 CORS_ALLOW_ORIGIN_WILDCARD 스위치를 뒀다.
	 */
	function sendFetch(body) {
		return fetch(endpoint, {
			method: 'POST',
			credentials: 'include',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		}).then(function (res) {
			if (!res.ok && window.console) {
				console.warn('[touchpoint] collect 실패', res.status);
			}
			return res.ok;
		}).catch(function (err) {
			// CORS 로 막히면 여기로 온다. 브라우저는 상태 코드를 알려주지 않는다 —
			// 실패 이유는 콘솔의 CORS 경고에만 있다.
			if (window.console) console.warn('[touchpoint] collect 오류', err);
			return false;
		});
	}

	/* sendBeacon 경로.
	 *
	 * Blob 의 type 을 text/plain 으로 둔다. application/json 으로 두면
	 * 단순 요청이 아니게 되어 preflight 가 필요해지는데, sendBeacon 은
	 * preflight 를 기다릴 수 없어서 그대로 실패한다.
	 * 서버가 Content-Type 이 아니라 본문 내용으로 파싱하는 이유다.
	 */
	function sendBeacon(body) {
		if (!navigator.sendBeacon) return false;

		var blob = new Blob([JSON.stringify(body)], { type: 'text/plain;charset=UTF-8' });

		return navigator.sendBeacon(endpoint, blob);
	}

	function flush(leaving) {
		if (!queue.length) return;

		var batch = queue.slice();
		queue.length = 0;

		for (var i = 0; i < batch.length; i++) {
			// 떠나는 중이면 beacon 만 쓴다. fetch 는 취소될 수 있다.
			if (leaving) sendBeacon(batch[i]);
			else sendFetch(batch[i]);
		}
	}

	var tp = {
		/** 즉시 전송. 기본은 fetch 이고, 두 번째 인자로 경로를 고를 수 있다. */
		track: function (event, extra, transport) {
			var body = payload(event, extra);

			return transport === 'beacon' ? sendBeacon(body) : sendFetch(body);
		},

		/** 나중에 보낼 것을 쌓아 둔다. 이탈 시 beacon 으로 한 번에 나간다. */
		queue: function (event, extra) {
			queue.push(payload(event, extra));

			return queue.length;
		},

		flush: flush,

		/** 진단용. 콘솔에서 설정을 확인할 수 있게. */
		config: { endpoint: endpoint, workId: workId, vid: vid }
	};

	window.tp = tp;

	/* 이탈 시 비우기.
	 *
	 * unload 가 아니라 pagehide 와 visibilitychange 를 쓴다.
	 * 모바일 사파리는 탭을 백그라운드로 보낼 때 unload 를 부르지 않는다 —
	 * unload 만 듣고 있으면 모바일 이탈을 통째로 놓친다.
	 */
	window.addEventListener('pagehide', function () { flush(true); });
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') flush(true);
	});

	// 페이지 조회는 바로 보낸다. 이게 preflight 를 띄우는 요청이다.
	tp.track('page_view');
})();
