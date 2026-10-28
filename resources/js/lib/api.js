const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

async function request(method, url, body) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.status === 401) {
        window.location.assign('/login');
    }

    const data = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        throw new Error(data?.message || `Request failed (${response.status})`);
    }

    return data;
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, body = {}) => request('POST', url, body),
    patch: (url, body = {}) => request('PATCH', url, body),
    delete: (url) => request('DELETE', url),
};
