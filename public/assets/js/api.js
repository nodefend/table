/* TableFlow v2 — HTTP client dùng chung cho TV1 và TV2.
 * API_CONTRACT.md v2: response success/message/data hoặc success/message/error.
 */
(() => { 
    'use strict';
    const script = document.currentScript;
    const base = new URL(script.dataset.apiBase || '../../api/', script.src)
    if(base.origin !== location.origin || !base.pathname.endsWith('/')) {
        throw new Error('API base phải cùng origin và kết thúc bằng /.');
    }
    let csrfToken = '';
    const polls = new Set();

    class ApiError extends Error {
        constructor(message, status = 0, code = 'NETWORK_ERROR', details = null) {
            super(message);
            this.name = 'ApiError';
            this.status = status;
            this.code = code;
            this.details = details;
        }
    }

    function stopAllPolling() {
        for (const stop of polls) stop();
    }

    function setCsrfToken(token) {
        csrfToken = typeof token === 'string' ? token : '';
    }

    // Không tự retry POST/PATCH: server có thể đã ghi dữ liệu dù mất response.
    async function request(path, { method = 'GET', query = {}, body,
        signal, timeout = 10000, cache = 'no-store' } = {}) {
        method = method.toUpperCase();
        if (!['GET', 'POST', 'PATCH'].includes(method)) throw new TypeError('HTTP method không hỗ trợ.');
        if (!/^(customer|staff)\/[a-z][a-z-]*\.php$/.test(path)) {
            throw new TypeError('Endpoint không hợp lệ.');
        }
        if (method === 'GET' && body !== undefined) throw new TypeError('GET không có body.');
        if (!Number.isFinite(timeout) || timeout <= 0) throw new TypeError('Timeout không hợp lệ.');
        const url = new URL(path, base);
        for (const [key, value] of Object.entries(query)) {
            if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
        }
        const headers = { Accept: 'application/json' };
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        const staffWrite = path.startsWith('staff/') && method !== 'GET' && path !== 'staff/login.php';
        if (staffWrite) {
            if (!csrfToken) throw new ApiError('Thiếu CSRF token.', 0, 'CSRF_TOKEN_MISSING');
            headers['X-CSRF-Token'] = csrfToken;
        }
        const controller = new AbortController();
        const abort = () => controller.abort();
        if (signal?.aborted) abort();
        else signal?.addEventListener('abort', abort, { once: true });
        let expired = false;
        const timer = setTimeout(() => { expired = true; abort(); }, timeout);
        try {
            const response = await fetch(url, {
                method, headers, credentials: 'same-origin', mode: 'same-origin',
                redirect: 'error', cache, signal: controller.signal,
                ...(body === undefined ? {} : { body: JSON.stringify(body) })
            });
            // fetch không reject chỉ vì HTTP 4xx/5xx.
            if (response.status === 401 && path.startsWith('staff/') && path !== 'staff/login.php') {
                setCsrfToken('');
                stopAllPolling();
            }
            const text = await response.text();
            let result;
            try { result = JSON.parse(text); }
            catch { throw new ApiError('Máy chủ không trả JSON hợp lệ.', response.status, 'INVALID_JSON_RESPONSE'); }
            if (!response.ok || result?.success === false) {
                throw new ApiError(result?.message || result?.error?.message || `HTTP ${response.status}`,
                    response.status, result?.error?.code || `HTTP_${response.status}`, result?.error?.details ?? null);
            }
            if (result?.success !== true || !Object.hasOwn(result, 'data')) {
                throw new ApiError('Phản hồi không đúng hợp đồng API.', response.status, 'INVALID_RESPONSE');
            }
            return result.data;
        } catch (error) {
            if (error instanceof ApiError) throw error;
            if (expired) throw new ApiError('Máy chủ phản hồi quá thời gian.', 0, 'TIMEOUT');
            if (controller.signal.aborted) throw new ApiError('Request đã bị hủy.', 0, 'ABORTED');
            throw new ApiError('Không thể kết nối máy chủ.', 0, 'NETWORK_ERROR');
        } finally {
            clearTimeout(timer);
            signal?.removeEventListener('abort', abort);
        }
    }

    const get = (path, query = {}, options = {}) => request(path, { ...options, method: 'GET', query });
    const post = (path, body = {}, options = {}) => request(path, { ...options, method: 'POST', body });
    const patch = (path, body, options = {}) => request(path, { ...options, method: 'PATCH', body });

    // TV2: token nằm trong bộ nhớ; sau reload PHP phải cấp lại token hợp lệ.
    async function login(credentials) {
        const data = await post('staff/login.php', credentials);
        stopAllPolling();
        setCsrfToken(data.csrf_token);
        return data;
    }
    async function logout() {
        stopAllPolling();
        const data = await post('staff/logout.php');
        setCsrfToken('');
        return data;
    }

    // Long Polling: bootstrap cursor -> snapshot -> chờ event -> cập nhật UI.
    // Mỗi luồng chỉ có một request poll đang chạy; caller stop() khi đổi branch.
    // onEvents phải hoàn thành cập nhật/refetch trước khi cursor được xác nhận.
    function startPolling({ branchId, onSnapshot, onEvents, onError = () => {},
        onState = () => {}, maxRetries = 5 } = {}) {
        if (typeof onSnapshot !== 'function' || typeof onEvents !== 'function') {
            throw new TypeError('Cần onSnapshot và onEvents.');
        }
        if (!Number.isSafeInteger(maxRetries) || maxRetries < 0) throw new TypeError('maxRetries không hợp lệ.');
        const controller = new AbortController();
        const signal = controller.signal;
        let cursor = null;
        let failures = 0;
        let waitTimer, wake;
        const stop = () => {
            controller.abort();
            clearTimeout(waitTimer);
            wake?.();
            polls.delete(stop);
        };
        const delay = ms => new Promise(resolve => {
            if (signal.aborted) return resolve();
            wake = resolve;
            waitTimer = setTimeout(resolve, ms);
        });
        const poll = query => get('staff/table-events.php', { branch_id: branchId, ...query },
            { signal, timeout: 30000 });
        function validate(data, previous) {
            if (!data || !Array.isArray(data.events) || !Number.isSafeInteger(data.next_since_id) ||
                data.next_since_id < 0 || typeof data.has_more !== 'boolean') {
                throw new ApiError('Event response không hợp lệ.', 0, 'INVALID_EVENT_RESPONSE');
            }
            if (previous !== null) {
                let last = previous;
                for (const event of data.events) {
                    if (!Number.isSafeInteger(event?.id) || event.id <= last) {
                        throw new ApiError('ID sự kiện không tăng.', 0, 'INVALID_EVENT_RESPONSE');
                    }
                    last = event.id;
                }
                if (data.next_since_id !== last || (data.has_more && !data.events.length)) {
                    throw new ApiError('Cursor sự kiện không hợp lệ.', 0, 'INVALID_EVENT_RESPONSE');
                }
            }
        }
        async function run() {
            try {
                while (!signal.aborted) {
                    try {
                        if (cursor === null) {
                            onState('connecting');
                            const initial = await poll({ bootstrap: 1 });
                            validate(initial, null);
                            if (initial.events.length || initial.has_more) throw new ApiError('Bootstrap không hợp lệ.', 0, 'INVALID_EVENT_RESPONSE');
                            if (signal.aborted) break;
                            await onSnapshot({ signal, branchId });
                            if (signal.aborted) break;
                            cursor = initial.next_since_id;
                        }
                        onState('connected');
                        const batch = await poll({ since_id: cursor });
                        if (signal.aborted) break;
                        validate(batch, cursor);
                        if (batch.events.length) await onEvents(batch.events, { signal, branchId });
                        if (signal.aborted) break;
                        cursor = batch.next_since_id;
                        failures = 0;
                        // Server thực sự giữ request tối đa 20s; delay nhỏ tránh loop nóng nếu trả ngay.
                        if (!batch.has_more) await delay(250);
                    } catch (error) {
                        if (signal.aborted) break;
                        failures++;
                        onError(error);
                        // Cursor vượt high-water mark: bootstrap + snapshot lại.
                        if (error.status === 422) cursor = null;
                        const retryable = error.status === 422 || error.status === 429 || error.status >= 500 ||
                            ['NETWORK_ERROR', 'TIMEOUT'].includes(error.code);
                        if (!retryable || failures > maxRetries) break;
                        onState('reconnecting');
                        await delay(Math.min(1000 * 2 ** (failures - 1), 15000));
                    }
                }
            } finally {
                stop();
                onState('stopped');
            }
        }
        polls.add(stop);
        const done = run();
        return { stop, done, get cursor() { return cursor; } };
    }

    window.TableFlowAPI = Object.freeze({ request, get, post, patch, ApiError,
        setCsrfToken, login, logout, startPolling, stopAllPolling });
})();

