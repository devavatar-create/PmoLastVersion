// Service worker for push notices from PMO Planning (registered from Settings > การแจ้งเตือน).
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'PMO', body: event.data ? event.data.text() : '' }; }
    event.waitUntil(self.registration.showNotification(data.title || 'PMO', {
        body: data.body || '',
        icon: 'images/logo.png',
        data: { url: data.url || './' },
        tag: data.tag || undefined,          // a newer notice of the same group replaces the one on screen
        renotify: !!data.tag,
        // new / waiting work: "รับงาน" opens the project's department window and asks to accept (the page still checks and confirms)
        actions: data.accept ? [{ action: 'accept', title: 'รับงาน' }, { action: 'open', title: 'เปิดดู' }] : [],
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    let url = (event.notification.data && event.notification.data.url) || './';
    if (event.action === 'accept') url += (url.includes('?') ? '&' : '?') + 'accept=1';
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        for (const c of list) { if (c.url === url && 'focus' in c) return c.focus(); }
        return clients.openWindow(url);
    }));
});
