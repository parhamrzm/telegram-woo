/**
 * Cloudflare Worker — Telegram Bot API Proxy
 * این Worker درخواستها را به api.telegram.org فوروارد میکند
 */
export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const targetUrl = 'https://api.telegram.org' + url.pathname + url.search;

    // ساخت درخواست جدید
    const newRequest = new Request(targetUrl, {
      method: request.method,
      headers: request.headers,
      body: request.body,
    });

    // حذف هدرهای problematic
    const headers = new Headers(newRequest.headers);
    headers.delete('cf-connecting-ip');
    headers.delete('cf-ipcountry');
    headers.delete('cf-ray');
    headers.delete('cf-visitor');
    headers.delete('x-forwarded-proto');
    headers.delete('x-real-ip');

    const modifiedRequest = new Request(targetUrl, {
      method: request.method,
      headers: headers,
      body: request.body,
    });

    try {
      const response = await fetch(modifiedRequest);

      // CORS headers
      const newResponse = new Response(response.body, response);
      newResponse.headers.set('Access-Control-Allow-Origin', '*');
      newResponse.headers.set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
      newResponse.headers.set('Access-Control-Allow-Headers', '*');

      return newResponse;
    } catch (err) {
      return new Response(JSON.stringify({ ok: false, error: err.message }), {
        status: 500,
        headers: { 'Content-Type': 'application/json' },
      });
    }
  },
};