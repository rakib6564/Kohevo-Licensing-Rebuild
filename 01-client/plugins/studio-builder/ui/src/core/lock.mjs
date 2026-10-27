// Kohevo Studio builder — advisory edit-session coordination.
//
// Uses the server's short-lived `studiobuilder_locks` row (TTL, DB clock) to
// warn when someone else has the page open. It never blocks editing and is
// never a substitute for revision concurrency: every write still carries
// `expected_revision_id`. The lock is refreshed while the builder is open and
// released when the tab goes away; if the tab dies it simply expires.

export class EditLock {
  constructor({ transport, pageId, onChange, intervalMs = 45000, setIntervalImpl, clearIntervalImpl }) {
    this.transport = transport;
    this.pageId = pageId;
    this.onChange = onChange || (() => {});
    this.intervalMs = intervalMs;
    this.setIntervalImpl = setIntervalImpl || ((fn, ms) => setInterval(fn, ms));
    this.clearIntervalImpl = clearIntervalImpl || ((h) => clearInterval(h));
    this.token = null;
    this.handle = null;
    this.stopped = false;
    this.state = { held: false, otherEditor: false };
  }

  apply(res) {
    if (!res || !res.ok) return; // advisory only: a failed heartbeat changes nothing
    const lock = res.data.lock || {};
    this.token = lock.held ? lock.lock_token : null;
    this.state = { held: !!lock.held, otherEditor: !!lock.other_editor };
    this.onChange(this.state);
  }

  // stop() may come while the first acquire is still on its way: the heartbeat must then never start, and a lock
  // granted meanwhile is given back at once (otherwise its interval would run forever, and the row would be held).
  async start() {
    const res = await this.transport.lockAcquire(this.pageId);
    if (this.stopped) {
      const lock = res && res.ok && res.data.lock;
      if (lock && lock.held && lock.lock_token) this.transport.lockRelease(this.pageId, lock.lock_token);
      return;
    }
    this.apply(res);
    if (this.handle === null) this.handle = this.setIntervalImpl(() => this.beat(), this.intervalMs);
  }

  async beat() {
    const res = this.token
      ? await this.transport.lockRefresh(this.pageId, this.token)
      : await this.transport.lockAcquire(this.pageId);
    if (!this.stopped) this.apply(res);
  }

  stop() {
    this.stopped = true;
    if (this.handle !== null) {
      this.clearIntervalImpl(this.handle);
      this.handle = null;
    }
    if (this.token) {
      const token = this.token;
      this.token = null;
      return this.transport.lockRelease(this.pageId, token);
    }
    return Promise.resolve(null);
  }
}
